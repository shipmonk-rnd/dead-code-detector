<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Command\Output;
use PHPStan\Diagnose\DiagnoseExtension;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\EntrypointCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
use ShipMonk\PHPStan\DeadCode\Crate\Method;
use ShipMonk\PHPStan\DeadCode\Crate\Visibility;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_merge_recursive;
use function array_slice;
use function count;
use function explode;
use function in_array;
use function sprintf;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule, DiagnoseExtension
{

    public const ERROR_IDENTIFIER = 'shipmonk.deadMethod';
    private const UNSUPPORTED_MAGIC_METHODS = [
        '__invoke' => null,
        '__toString' => null,
        '__destruct' => null,
        '__call' => null,
        '__callStatic' => null,
        '__get' => null,
        '__set' => null,
        '__isset' => null,
        '__unset' => null,
        '__sleep' => null,
        '__wakeup' => null,
        '__serialize' => null,
        '__unserialize' => null,
        '__set_state' => null,
        '__debugInfo' => null,
    ];

    private ClassHierarchy $classHierarchy;

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      methods: array<string, array{line: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * @var array<string, list<string>>
     */
    private array $methodAlternativesCache = [];

    private bool $reportTransitivelyDeadMethodAsSeparateError;

    /**
     * @var array<string, array{string, int}> methodKey => [file, line]
     */
    private array $blackMethods = [];

    /**
     * @var array<string, list<Call>> methodName => Call[]
     */
    private array $mixedCalls = [];

    /**
     * @var array<string, list<string>> caller => callee[]
     */
    private array $callGraph = [];

    public function __construct(
        ClassHierarchy $classHierarchy,
        bool $reportTransitivelyDeadMethodAsSeparateError
    )
    {
        $this->classHierarchy = $classHierarchy;
        $this->reportTransitivelyDeadMethodAsSeparateError = $reportTransitivelyDeadMethodAsSeparateError;
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): array
    {
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        /** @var list<Call> $calls */
        $calls = [];
        $methodDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $entrypointData = $node->get(EntrypointCollector::class);

        /** @var array<string, list<list<string>>> $callData */
        $callData = array_merge_recursive($methodCallData, $entrypointData);
        unset($methodCallData, $entrypointData);

        foreach ($callData as $callsPerFile) {
            foreach ($callsPerFile as $callStrings) {
                foreach ($callStrings as $callString) {
                    $call = Call::fromString($callString);

                    if ($call->callee->className === null) {
                        $this->mixedCalls[$call->callee->methodName][] = $call;
                        continue;
                    }

                    $calls[] = $call;
                }
            }
        }

        foreach ($methodDeclarationData as $file => $data) {
            foreach ($data as $typeData) {
                $typeName = $typeData['name'];
                $this->typeDefinitions[$typeName] = [
                    'kind' => $typeData['kind'],
                    'name' => $typeName,
                    'file' => $file,
                    'methods' => $typeData['methods'],
                    'parents' => $typeData['parents'],
                    'traits' => $typeData['traits'],
                    'interfaces' => $typeData['interfaces'],
                ];
            }
        }

        unset($methodDeclarationData);

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $definition = $this->getMethodKey($typeName, $methodName);
                $this->blackMethods[$definition] = [$file, $methodData['line']];

                if (isset($this->mixedCalls[$methodName])) {
                    foreach ($this->mixedCalls[$methodName] as $originalCall) {
                        $calls[] = new Call(
                            $originalCall->caller,
                            new Method($typeName, $methodName),
                            $originalCall->possibleDescendantCall,
                        );
                    }
                }
            }
        }

        $whiteCallees = [];

        foreach ($calls as $call) {
            $isWhite = $this->isConsideredWhite($call);

            $alternativeCalleeKeys = $this->getAlternativeMethodKeys($call->callee, $call->possibleDescendantCall);
            $alternativeCallerKeys = $call->caller !== null ? $this->getAlternativeMethodKeys($call->caller, false) : [];

            foreach ($alternativeCalleeKeys as $alternativeCalleeKey) {
                foreach ($alternativeCallerKeys as $alternativeCallerKey) {
                    $this->callGraph[$alternativeCallerKey][] = $alternativeCalleeKey;
                }

                if ($isWhite) {
                    $whiteCallees[] = $alternativeCalleeKey;
                }
            }
        }

        foreach ($whiteCallees as $whiteCalleeKey) {
            $this->markTransitiveCallsWhite($whiteCalleeKey);
        }

        foreach ($this->blackMethods as $blackMethodKey => $_) {
            if ($this->isNeverReportedAsDead($blackMethodKey)) {
                unset($this->blackMethods[$blackMethodKey]);
            }
        }

        $errors = [];

        if ($this->reportTransitivelyDeadMethodAsSeparateError) {
            foreach ($this->blackMethods as $deadMethodKey => [$file, $line]) {
                $errors[] = $this->buildError($deadMethodKey, [], $file, $line);
            }

            return $errors;
        }

        $deadGroups = $this->groupDeadMethods();

        foreach ($deadGroups as $deadGroupKey => $deadSubgroupKeys) {
            [$file, $line] = $this->blackMethods[$deadGroupKey]; // @phpstan-ignore offsetAccess.notFound
            $subGroupMap = [];

            foreach ($deadSubgroupKeys as $deadSubgroupKey) {
                $subGroupMap[$deadSubgroupKey] = $this->blackMethods[$deadSubgroupKey]; // @phpstan-ignore offsetAccess.notFound
            }

            $errors[] = $this->buildError($deadGroupKey, $subGroupMap, $file, $line);
        }

        return $errors;
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenMethods
     */
    private function fillTraitUsages(string $typeName, array $usedTraits, array $overriddenMethods): void
    {
        foreach ($usedTraits as $traitName => $adaptations) {
            $traitMethods = $this->typeDefinitions[$traitName]['methods'] ?? [];

            $excludedMethods = array_merge(
                $overriddenMethods,
                $adaptations['excluded'] ?? [],
            );

            foreach ($traitMethods as $traitMethod => $traitMethodData) {
                $declaringTraitMethodDefinition = $this->getMethodKey($traitName, $traitMethod);
                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $aliasMethodDefinition = $this->getMethodKey($typeName, $aliasMethodName);
                    $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodDefinition, $aliasMethodDefinition);
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $overriddenMethods[] = $traitMethod;
                $usedTraitMethodDefinition = $this->getMethodKey($typeName, $traitMethod);
                $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodDefinition, $usedTraitMethodDefinition);
            }

            $this->fillTraitUsages($typeName, $this->getTraitUsages($traitName), $overriddenMethods);
        }
    }

    /**
     * @param list<string> $ancestorNames
     */
    private function fillClassHierarchy(string $typeName, array $ancestorNames): void
    {
        foreach ($ancestorNames as $ancestorName) {
            $this->classHierarchy->registerClassPair($ancestorName, $typeName);

            $this->fillClassHierarchy($typeName, $this->getAncestorNames($ancestorName));
        }
    }

    private function isAnonymousClass(?string $className): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return $className !== null && strpos($className, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getAlternativeMethodKeys(Method $method, bool $possibleDescendant): array
    {
        if ($method->className === null) {
            throw new LogicException('Those were eliminated above, should never happen');
        }

        $methodKey = $method->toString();
        $cacheKey = $methodKey . ';' . ($possibleDescendant ? '1' : '0');

        if (isset($this->methodAlternativesCache[$cacheKey])) {
            return $this->methodAlternativesCache[$cacheKey];
        }

        $result = [$methodKey];

        if ($possibleDescendant) {
            foreach ($this->classHierarchy->getClassDescendants($method->className) as $descendantName) {
                $result[] = $this->getMethodKey($descendantName, $method->methodName);
            }
        }

        // each descendant can be a trait user
        foreach ($result as $resultKey) {
            $traitMethodKey = $this->classHierarchy->getDeclaringTraitMethodKey($resultKey);

            if ($traitMethodKey !== null) {
                $result[] = $traitMethodKey;
            }
        }

        $this->methodAlternativesCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param array<string, null> $visitedKeys
     */
    private function markTransitiveCallsWhite(string $callerKey, array $visitedKeys = []): void
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->callGraph[$callerKey] ?? [];

        unset($this->blackMethods[$callerKey]);

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMethods[$calleeKey])) {
                continue;
            }

            $this->markTransitiveCallsWhite($calleeKey, array_merge($visitedKeys, [$calleeKey => null]));
        }
    }

    /**
     * @param array<string, null> $visitedKeys
     * @return list<string>
     */
    private function getTransitiveDeadCalls(string $callerKey, array $visitedKeys = []): array
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->callGraph[$callerKey] ?? [];

        $result = [];

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMethods[$calleeKey])) {
                continue;
            }

            $result[] = $calleeKey;

            foreach ($this->getTransitiveDeadCalls($calleeKey, array_merge($visitedKeys, [$calleeKey => null])) as $transitiveDead) {
                $result[] = $transitiveDead;
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    private function groupDeadMethods(): array
    {
        $deadGroups = [];

        /** @var array<string, true> $deadMethodsWithCaller */
        $deadMethodsWithCaller = [];

        foreach ($this->callGraph as $caller => $callees) {
            if (!array_key_exists($caller, $this->blackMethods)) {
                continue;
            }

            foreach ($callees as $callee) {
                if (array_key_exists($callee, $this->blackMethods)) {
                    $deadMethodsWithCaller[$callee] = true;
                }
            }
        }

        $methodsGrouped = [];

        foreach ($this->blackMethods as $deadMethodKey => $_) {
            if (isset($methodsGrouped[$deadMethodKey])) {
                continue;
            }

            if (isset($deadMethodsWithCaller[$deadMethodKey])) {
                continue; // has a caller, thus should be part of a group, not a group representative
            }

            $deadGroups[$deadMethodKey] = [];
            $methodsGrouped[$deadMethodKey] = true;

            $transitiveMethodKeys = $this->getTransitiveDeadCalls($deadMethodKey);

            foreach ($transitiveMethodKeys as $transitiveMethodKey) {
                $deadGroups[$deadMethodKey][] = $transitiveMethodKey;
                $methodsGrouped[$transitiveMethodKey] = true;
            }
        }

        // now only cycles remain, lets pick group representatives based on first occurrence
        foreach ($this->blackMethods as $deadMethodKey => $_) {
            if (isset($methodsGrouped[$deadMethodKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveDeadCalls($deadMethodKey);

            $deadGroups[$deadMethodKey] = [];
            $methodsGrouped[$deadMethodKey] = true;

            foreach ($transitiveDeadMethods as $transitiveDeadMethodKey) {
                $deadGroups[$deadMethodKey][] = $transitiveDeadMethodKey;
                $methodsGrouped[$transitiveDeadMethodKey] = true;
            }
        }

        return $deadGroups;
    }

    /**
     * @param array<string, array{string, int}> $transitiveDeadMethodKeys
     */
    private function buildError(
        string $deadMethodKey,
        array $transitiveDeadMethodKeys,
        string $file,
        int $line
    ): IdentifierRuleError
    {
        $builder = RuleErrorBuilder::message('Unused ' . $deadMethodKey)
            ->file($file)
            ->line($line)
            ->identifier(self::ERROR_IDENTIFIER);

        $metadata = [];
        $metadata[$deadMethodKey] = [
            'file' => $file,
            'line' => $line,
            'transitive' => false,
        ];

        foreach ($transitiveDeadMethodKeys as $transitiveDeadMethodKey => [$transitiveDeadMethodFile, $transitiveDeadMethodLine]) {
            $builder->addTip("Thus $transitiveDeadMethodKey is transitively also unused");

            $metadata[$transitiveDeadMethodKey] = [
                'file' => $transitiveDeadMethodFile,
                'line' => $transitiveDeadMethodLine,
                'transitive' => true,
            ];
        }

        $builder->metadata($metadata);

        return $builder->build();
    }

    /**
     * @return list<string>
     */
    private function getAncestorNames(string $typeName): array
    {
        return array_merge(
            array_keys($this->typeDefinitions[$typeName]['parents'] ?? []),
            array_keys($this->typeDefinitions[$typeName]['traits'] ?? []),
            array_keys($this->typeDefinitions[$typeName]['interfaces'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function getTypeMethods(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['methods'] ?? []);
    }

    /**
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

    private function getMethodKey(string $typeName, string $methodName): string
    {
        return $typeName . '::' . $methodName;
    }

    private function isConsideredWhite(Call $call): bool
    {
        return $call->caller === null
            || $this->isAnonymousClass($call->caller->className)
            || array_key_exists($call->caller->methodName, self::UNSUPPORTED_MAGIC_METHODS);
    }

    private function isNeverReportedAsDead(string $methodKey): bool
    {
        [$typeName, $methodName] = explode('::', $methodKey); // @phpstan-ignore offsetAccess.notFound

        $kind = $this->typeDefinitions[$typeName]['kind'] ?? null;
        $abstract = $this->typeDefinitions[$typeName]['methods'][$methodName]['abstract'] ?? false;
        $visibility = $this->typeDefinitions[$typeName]['methods'][$methodName]['visibility'] ?? 0;

        if ($kind === Kind::TRAIT && $abstract) {
            // abstract methods in traits make sense (not dead) only when called within the trait itself, but that is hard to detect for now, so lets ignore them completely
            // the difference from interface methods (or abstract methods) is that those methods can be called over the interface, but you cannot call method over trait
            return true;
        }

        if ($methodName === '__construct' && ($visibility & Visibility::PRIVATE) !== 0) {
            // private constructors are often used to deny instantiation
            return true;
        }

        if (array_key_exists($methodName, self::UNSUPPORTED_MAGIC_METHODS)) {
            return true;
        }

        return false;
    }

    public function print(Output $output): void
    {
        if ($this->mixedCalls === [] || !$output->isDebug()) {
            return;
        }

        $maxExamplesToShow = 20;
        $output->writeLineFormatted(sprintf('<fg=red>Found %d methods called over unknown type</>:', count($this->mixedCalls)));

        foreach (array_slice($this->mixedCalls, 0, $maxExamplesToShow) as $methodName => $calls) {
            $output->writeFormatted(sprintf(' • <fg=white>%s</>', $methodName));

            $exampleCaller = $this->getExampleCaller($calls);

            if ($exampleCaller !== null) {
                $output->writeFormatted(sprintf(', for example in <fg=white>%s</>', $exampleCaller));
            }

            $output->writeLineFormatted('');
        }

        if (count($this->mixedCalls) > $maxExamplesToShow) {
            $output->writeLineFormatted(sprintf('... and %d more', count($this->mixedCalls) - $maxExamplesToShow));
        }

        $output->writeLineFormatted('');
        $output->writeLineFormatted('Thus, any method named the same is considered used, no matter its declaring class!');
        $output->writeLineFormatted('');
    }

    /**
     * @param list<Call> $calls
     */
    private function getExampleCaller(array $calls): ?string
    {
        foreach ($calls as $call) {
            if ($call->caller !== null) {
                return $call->caller->toString();
            }
        }

        return null;
    }

}

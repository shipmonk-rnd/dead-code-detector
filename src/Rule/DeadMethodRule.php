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
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\EntrypointCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantFetch;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodCall;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
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
use function substr;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule, DiagnoseExtension // TODO rename to DeadCodeRule
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
     *      constants: array<string, array{line: int}>,
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
    private array $memberAlternativesCache = [];

    private bool $reportTransitivelyDeadMethodAsSeparateError;

    private bool $trackCallsOnMixed;

    /**
     * @var array<string, array{string, int, string, string}> methodKey => [file, line, typename, methodname]
     */
    private array $blackMethods = [];

    /**
     * @var array<string, array{string, int, string, string}> constantKey => [file, line, typename, constantname]
     */
    private array $blackConstants = [];

    /**
     * @var array<string, list<string>> originMethodKey => constantKey[]
     */
    private array $constantFetches = [];

    /**
     * @var array<string, list<ClassMethodCall>> methodName => Call[]
     */
    private array $mixedCalls = [];

    /**
     * @var array<string, list<string>> caller => callee[]
     */
    private array $callGraph = []; // TODO include also const fetches?

    public function __construct(
        ClassHierarchy $classHierarchy,
        bool $reportTransitivelyDeadMethodAsSeparateError,
        bool $trackCallsOnMixed
    )
    {
        $this->classHierarchy = $classHierarchy;
        $this->reportTransitivelyDeadMethodAsSeparateError = $reportTransitivelyDeadMethodAsSeparateError;
        $this->trackCallsOnMixed = $trackCallsOnMixed;
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

        /** @var list<ClassMethodCall> $calls */
        $calls = [];
        /** @var list<ClassConstantFetch> $fetches */
        $fetches = [];

        $methodDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $constFetchData = $node->get(ConstantFetchCollector::class);
        $entrypointData = $node->get(EntrypointCollector::class);

        /** @var array<string, list<list<string>>> $callData */
        $callData = array_merge_recursive($methodCallData, $entrypointData);
        unset($methodCallData, $entrypointData);

        foreach ($callData as $callsPerFile) {
            foreach ($callsPerFile as $callStrings) {
                foreach ($callStrings as $callString) {
                    $call = ClassMethodCall::deserialize($callString);

                    if ($call->callee->className === null) {
                        $this->mixedCalls[$call->callee->memberName][] = $call;
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
                    'constants' => $typeData['constants'],
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
            $constants = $typeDefinition['constants'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeConstants($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $definition = ClassMethodRef::buildKey($typeName, $methodName);
                $this->blackMethods[$definition] = [$file, $methodData['line'], $typeName, $methodName];

                if (isset($this->mixedCalls[$methodName])) {
                    foreach ($this->mixedCalls[$methodName] as $originalCall) {
                        $calls[] = new ClassMethodCall(
                            $originalCall->caller,
                            new ClassMethodRef($typeName, $methodName),
                            $originalCall->possibleDescendantCall,
                        );
                    }
                }
            }

            foreach ($constants as $constantName => $constantData) {
                $definition = ClassConstantRef::buildKey($typeName, $constantName);
                $this->blackConstants[$definition] = [$file, $constantData['line'], $typeName, $constantName];
            }
        }

        foreach ($constFetchData as $file => $data) {
            foreach ($data as $constantData) {
                foreach ($constantData as $constantKey) {
                    $fetch = ClassConstantFetch::deserialize($constantKey);
                    $fetches[] = $fetch;

                    if ($fetch->origin !== null) {
                        $alternativeOriginKeys = $this->getAlternativeMemberKeys($fetch->origin, false);
                        $alternativeConstantKeys = $this->getAlternativeMemberKeys($fetch->fetch, $fetch->possibleDescendantFetch);

                        foreach ($alternativeOriginKeys as $alternativeOriginKey) {
                            foreach ($alternativeConstantKeys as $alternativeConstantKey) {
                                $this->constantFetches[$alternativeOriginKey][] = $alternativeConstantKey;
                            }
                        }
                    }
                }
            }
        }

        $whiteCallees = [];

        foreach ($calls as $call) {
            $isWhite = $this->isConsideredWhite($call);

            // TODO unify with consts? include consts to call graph?
            $alternativeCalleeKeys = $this->getAlternativeMemberKeys($call->callee, $call->possibleDescendantCall);
            $alternativeCallerKeys = $call->caller !== null ? $this->getAlternativeMemberKeys($call->caller, false) : [];

            foreach ($alternativeCalleeKeys as $alternativeCalleeKey) {
                foreach ($alternativeCallerKeys as $alternativeCallerKey) {
                    $this->callGraph[$alternativeCallerKey][] = $alternativeCalleeKey;
                }

                if ($isWhite) {
                    $whiteCallees[] = $alternativeCalleeKey;
                }
            }
        }

        foreach ($fetches as $fetch) {
            if ($fetch->origin === null) {
                $alternativeConstantKeys = $this->getAlternativeMemberKeys($fetch->fetch, $fetch->possibleDescendantFetch);

                foreach ($alternativeConstantKeys as $alternativeConstantKey) {
                    unset($this->blackConstants[$alternativeConstantKey]);
                }
            }
            // else utilize call-graph traversal via markTransitivesWhite
        }

        foreach ($whiteCallees as $whiteCalleeKey) {
            $this->markTransitivesWhite($whiteCalleeKey);
        }

        foreach ($this->blackMethods as $blackMethodKey => [$file, $line, $className, $methodName]) {
            if ($this->isNeverReportedAsDead($className, $methodName)) {
                unset($this->blackMethods[$blackMethodKey]);
            }
        }

        $errors = [];

        if ($this->reportTransitivelyDeadMethodAsSeparateError) {
            foreach ($this->blackMethods as [$file, $line, $typeName, $methodName]) {
                $errors[] = $this->buildError($typeName, $methodName, [], $file, $line);
            }

            foreach ($this->blackConstants as [$file, $line, $typeName, $constantName]) {
                $errors[] = $this->buildError($typeName, $constantName, [], $file, $line);
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

            // @phpstan-ignore offsetAccess.notFound
            [$typeName, $memberName] = explode('::', substr($deadGroupKey, 2)); // TODO remove this hack
            $errors[] = $this->buildError($typeName, $memberName, $subGroupMap, $file, $line);
        }

        return $errors;
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenMethods
     */
    private function fillTraitMethodUsages(string $typeName, array $usedTraits, array $overriddenMethods): void
    {
        foreach ($usedTraits as $traitName => $adaptations) {
            $traitMethods = $this->typeDefinitions[$traitName]['methods'] ?? [];

            $excludedMethods = array_merge(
                $overriddenMethods,
                $adaptations['excluded'] ?? [],
            );

            foreach ($traitMethods as $traitMethod => $traitMethodData) {
                $declaringTraitMethodDefinition = ClassMethodRef::buildKey($traitName, $traitMethod);
                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $aliasMethodDefinition = ClassMethodRef::buildKey($typeName, $aliasMethodName);
                    $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $aliasMethodDefinition);
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $overriddenMethods[] = $traitMethod;
                $usedTraitMethodDefinition = ClassMethodRef::buildKey($typeName, $traitMethod);
                $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $usedTraitMethodDefinition);
            }

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($traitName), $overriddenMethods);
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenConstants
     */
    private function fillTraitConstantUsages(string $typeName, array $usedTraits, array $overriddenConstants): void
    {
        foreach ($usedTraits as $traitName => $_) {
            $traitConstants = $this->typeDefinitions[$traitName]['constants'] ?? [];

            $excludedConstants = $overriddenConstants;

            foreach ($traitConstants as $traitConstant => $__) {
                $declaringTraitConstantKey = ClassConstantRef::buildKey($traitName, $traitConstant);

                if (in_array($traitConstant, $excludedConstants, true)) {
                    continue;
                }

                $overriddenConstants[] = $traitConstant;
                $traitUserConstantKey = ClassConstantRef::buildKey($typeName, $traitConstant);
                $this->classHierarchy->registerTraitUsage($declaringTraitConstantKey, $traitUserConstantKey);
            }

            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($traitName), $overriddenConstants);
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
    private function getAlternativeMemberKeys(ClassMemberRef $member, bool $possibleDescendant): array
    {
        if ($member->className === null) {
            throw new LogicException('Those were eliminated above, should never happen');
        }

        $memberKey = $member->toKey();
        $cacheKey = $memberKey . ';' . ($possibleDescendant ? '1' : '0');

        if (isset($this->memberAlternativesCache[$cacheKey])) {
            return $this->memberAlternativesCache[$cacheKey];
        }

        $result = [$memberKey];

        if ($possibleDescendant) {
            foreach ($this->classHierarchy->getClassDescendants($member->className) as $descendantName) {
                $result[] = $member::buildKey($descendantName, $member->memberName);
            }
        }

        // each descendant can be a trait user
        foreach ($result as $resultKey) {
            $traitMethodKey = $this->classHierarchy->getDeclaringTraitMemberKey($resultKey);

            if ($traitMethodKey !== null) {
                $result[] = $traitMethodKey;
            }
        }

        $this->memberAlternativesCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param array<string, null> $visitedKeys
     */
    private function markTransitivesWhite(string $callerKey, array $visitedKeys = []): void
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->callGraph[$callerKey] ?? [];

        unset($this->blackMethods[$callerKey]);

        foreach ($this->constantFetches[$callerKey] ?? [] as $constantKey) {
            unset($this->blackConstants[$constantKey]);
        }

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMethods[$calleeKey])) {
                continue;
            }

            $this->markTransitivesWhite($calleeKey, array_merge($visitedKeys, [$calleeKey => null]));
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
        string $typeName,
        string $memberName,
        array $transitiveDeadMethodKeys,
        string $file,
        int $line
    ): IdentifierRuleError
    {
        $builder = RuleErrorBuilder::message('Unused ' . $typeName . '::' . $memberName)
            ->file($file)
            ->line($line)
            ->identifier(self::ERROR_IDENTIFIER);

        $metadata = [];
        $metadata[$typeName . '::' . $memberName] = [
            'file' => $file,
            'line' => $line,
            'transitive' => false,
        ];

        foreach ($transitiveDeadMethodKeys as $transitiveDeadMethodKey => [$transitiveDeadMethodFile, $transitiveDeadMethodLine]) {
            $transitiveDeadMethodRef = substr($transitiveDeadMethodKey, 2); // TODO remove this hack
            $builder->addTip("Thus $transitiveDeadMethodRef is transitively also unused");

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
     * @return list<string>
     */
    private function getTypeConstants(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['constants'] ?? []);
    }

    /**
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

    private function isConsideredWhite(ClassMethodCall $call): bool
    {
        return $call->caller === null
            || $this->isAnonymousClass($call->caller->className)
            || array_key_exists($call->caller->memberName, self::UNSUPPORTED_MAGIC_METHODS);
    }

    private function isNeverReportedAsDead(string $typeName, string $methodName): bool
    {
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
        if ($this->mixedCalls === [] || !$output->isDebug() || !$this->trackCallsOnMixed) {
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
     * @param list<ClassMethodCall> $calls
     */
    private function getExampleCaller(array $calls): ?string
    {
        foreach ($calls as $call) {
            if ($call->caller !== null) {
                return $call->caller->toHumanString();
            }
        }

        return null;
    }

}

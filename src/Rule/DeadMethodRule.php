<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\EntrypointCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\Method;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function in_array;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule
{

    private ClassHierarchy $classHierarchy;

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      methods: array<string, array{line: int, abstract: bool}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * @var array<string, list<string>>
     */
    private array $methodsToMarkAsUsedCache = [];

    private bool $reportTransitivelyDeadMethodAsSeparateError;

    /**
     * @var array<string, array{string, int}> methodKey => [file, line]
     */
    private array $deadMethods = [];

    /**
     * @var array<string, list<string>> caller => callee[]
     */
    private array $callGraph = [];

    public function __construct(
        ClassHierarchy $classHierarchy,
        bool $reportTransitivelyDeadMethodAsSeparateError = false
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

        $methodDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $entrypointData = $node->get(EntrypointCollector::class);

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

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $definition = $this->getMethodKey($typeName, $methodName);
                $this->deadMethods[$definition] = [$file, $methodData['line']];
            }
        }

        unset($methodDeclarationData);

        $whiteCallees = [];

        foreach ($methodCallData as $file => $callsInFile) {
            foreach ($callsInFile as $calls) {
                foreach ($calls as $callString) {
                    $call = Call::fromString($callString);

                    if ($this->containsAnonymousClass($call)) {
                        continue;
                    }

                    $callerKey = $call->caller === null ? '' : $call->caller->toString();
                    $isWhite = $call->caller === null || Method::isUnsupported($call->caller->methodName);

                    foreach ($this->getAlternativeCalleeKeys($call) as $possibleCalleeKey) {
                        $this->callGraph[$callerKey][] = $possibleCalleeKey;

                        if ($isWhite) {
                            $whiteCallees[] = $possibleCalleeKey;
                        }
                    }
                }
            }
        }

        unset($methodCallData);

        foreach ($whiteCallees as $whiteCalleeKey) {
            $this->markTransitiveCallsWhite($whiteCalleeKey);
        }

        foreach ($entrypointData as $file => $entrypointsInFile) {
            foreach ($entrypointsInFile as $entrypoints) {
                foreach ($entrypoints as $entrypoint) {
                    $call = Call::fromString($entrypoint);

                    foreach ($this->getAlternativeCalleeKeys($call) as $methodDefinition) {
                        unset($this->deadMethods[$methodDefinition]);
                    }

                    $this->markTransitiveCallsWhite($call->callee->toString());
                }
            }
        }

        $errors = [];

        if ($this->reportTransitivelyDeadMethodAsSeparateError) {
            foreach ($this->deadMethods as $deadMethodKey => [$file, $line]) {
                $errors[] = $this->buildError($deadMethodKey, [], $file, $line);
            }

            return $errors;
        }

        $deadGroups = $this->groupDeadMethods();

        foreach ($deadGroups as $deadGroupKey => $deadSubgroupKeys) {
            [$file, $line] = $this->deadMethods[$deadGroupKey]; // @phpstan-ignore offsetAccess.notFound
            $subGroupMap = [];

            foreach ($deadSubgroupKeys as $deadSubgroupKey) {
                $subGroupMap[$deadSubgroupKey] = $this->deadMethods[$deadSubgroupKey]; // @phpstan-ignore offsetAccess.notFound
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

    private function containsAnonymousClass(Call $call): bool
    {
        $callerClassName = $call->caller === null ? '' : $call->caller->className;
        $calleeClassName = $call->callee->className;

        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return strpos($callerClassName, 'AnonymousClass') === 0
            || strpos($calleeClassName, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getAlternativeCalleeKeys(Call $call): array
    {
        $calleeCacheKey = "{$call->callee->className}::{$call->callee->methodName}";

        if (isset($this->methodsToMarkAsUsedCache[$calleeCacheKey])) {
            return $this->methodsToMarkAsUsedCache[$calleeCacheKey];
        }

        $result = [$this->getMethodKey($call->callee->className, $call->callee->methodName)];

        if ($call->possibleDescendantCall) {
            foreach ($this->classHierarchy->getClassDescendants($call->callee->className) as $descendantName) {
                $result[] = $this->getMethodKey($descendantName, $call->callee->methodName);
            }
        }

        // each descendant can be a trait user
        foreach ($result as $methodDefinition) {
            $traitMethodKey = $this->classHierarchy->getDeclaringTraitMethodKey($methodDefinition);

            if ($traitMethodKey !== null) {
                $result[] = $traitMethodKey;
            }
        }

        $this->methodsToMarkAsUsedCache[$calleeCacheKey] = $result;

        return $result;
    }

    /**
     * @param array<string, null> $visitedKeys
     */
    private function markTransitiveCallsWhite(string $callerKey, array $visitedKeys = []): void
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $this->callGraph[$callerKey] ?? [];

        unset($this->deadMethods[$callerKey]);

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->deadMethods[$calleeKey])) {
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

            if (!isset($this->deadMethods[$calleeKey])) {
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
            if (!array_key_exists($caller, $this->deadMethods)) {
                continue;
            }

            foreach ($callees as $callee) {
                if (array_key_exists($callee, $this->deadMethods)) {
                    $deadMethodsWithCaller[$callee] = true;
                }
            }
        }

        $methodsGrouped = [];

        foreach ($this->deadMethods as $deadMethodKey => $_) {
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
        foreach ($this->deadMethods as $deadMethodKey => $_) {
            if (isset($methodsGrouped[$deadMethodKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveDeadCalls($deadMethodKey);

            $deadGroups[$deadMethodKey] = []; // TODO provide info to some Tip that those are cycles?
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
            ->identifier('shipmonk.deadMethod');

        $metadata = [];

        foreach ($transitiveDeadMethodKeys as $transitiveDeadMethodKey => [$transitiveDeadMethodFile, $transitiveDeadMethodLine]) {
            $builder->addTip("Thus $transitiveDeadMethodKey is transitively also unused");

            $metadata[$transitiveDeadMethodKey] = [
                'file' => $transitiveDeadMethodFile,
                'line' => $transitiveDeadMethodLine,
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

}

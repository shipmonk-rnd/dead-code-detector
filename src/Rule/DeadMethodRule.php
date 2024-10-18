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

        /** @var array<string, list<string>> $callGraph caller => callee[] */
        $callGraph = [];
        $deadMethods = [];

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
                $deadMethods[$definition] = [$file, $methodData['line']];
            }
        }

        unset($methodDeclarationData);

        foreach ($methodCallData as $file => $callsInFile) {
            foreach ($callsInFile as $calls) {
                foreach ($calls as $callString) {
                    $call = Call::fromString($callString);

                    if ($this->containsAnonymousClass($call)) {
                        continue;
                    }

                    $callerKey = $call->caller === null ? '' : $call->caller->toString();

                    foreach ($this->getAlternativeCalleeKeys($call) as $possibleCalleeKey) {
                        $callGraph[$callerKey][] = $possibleCalleeKey;
                    }
                }
            }
        }

        unset($methodCallData);

        $globalCallers = $callGraph[''] ?? []; // no caller is a global caller for now

        foreach ($globalCallers as $globalCalleeKey) {
            unset($deadMethods[$globalCalleeKey]);

            foreach ($this->getTransitiveCalleeKeys($globalCalleeKey, $callGraph) as $subCallKey) {
                unset($deadMethods[$subCallKey]);
            }
        }

        foreach ($entrypointData as $file => $entrypointsInFile) {
            foreach ($entrypointsInFile as $entrypoints) {
                foreach ($entrypoints as $entrypoint) {
                    $call = Call::fromString($entrypoint);

                    foreach ($this->getAlternativeCalleeKeys($call) as $methodDefinition) {
                        unset($deadMethods[$methodDefinition]);
                    }

                    foreach ($this->getTransitiveCalleeKeys($call->callee->toString(), $callGraph) as $subCallKey) {
                        unset($deadMethods[$subCallKey]);
                    }
                }
            }
        }

        $errors = [];

        if ($this->reportTransitivelyDeadMethodAsSeparateError) {
            foreach ($deadMethods as $deadMethodKey => [$file, $line]) {
                $errors[] = $this->buildError($deadMethodKey, [], $file, $line);
            }

            return $errors;
        }

        $deadGroups = $this->groupDeadMethods($deadMethods, $callGraph);

        foreach ($deadGroups as $deadGroupKey => $deadSubgroupKeys) {
            [$file, $line] = $deadMethods[$deadGroupKey]; // @phpstan-ignore offsetAccess.notFound
            $subGroupMap = [];

            foreach ($deadSubgroupKeys as $deadSubgroupKey) {
                $subGroupMap[$deadSubgroupKey] = $deadMethods[$deadSubgroupKey]; // @phpstan-ignore offsetAccess.notFound
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
     * @param array<string, list<string>> $callGraph
     * @param array<string, null> $visitedKeys
     * @return list<string>
     */
    private function getTransitiveCalleeKeys(string $callerKey, array $callGraph, array $visitedKeys = []): array
    {
        $result = [];
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $calleeKeys = $callGraph[$callerKey] ?? [];

        foreach ($calleeKeys as $calleeKey) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            $result[] = $calleeKey;
            $result = array_merge($result, $this->getTransitiveCalleeKeys($calleeKey, $callGraph, array_merge($visitedKeys, [$calleeKey => null])));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $deadMethods
     * @param array<string, list<string>> $callGraph
     * @return array<string, list<string>>
     */
    private function groupDeadMethods(array $deadMethods, array $callGraph): array
    {
        $deadGroups = [];

        /** @var array<string, true> $deadMethodsWithCaller */
        $deadMethodsWithCaller = [];

        foreach ($callGraph as $caller => $callees) {
            if (!array_key_exists($caller, $deadMethods)) {
                continue;
            }

            foreach ($callees as $callee) {
                if (array_key_exists($callee, $deadMethods)) {
                    $deadMethodsWithCaller[$callee] = true;
                }
            }
        }

        $methodsGrouped = [];

        foreach ($deadMethods as $deadMethodKey => $_) {
            if (isset($methodsGrouped[$deadMethodKey])) {
                continue;
            }

            if (isset($deadMethodsWithCaller[$deadMethodKey])) {
                continue; // has a caller, thus should be part of a group, not a group representative
            }

            $deadGroups[$deadMethodKey] = [];
            $methodsGrouped[$deadMethodKey] = true;

            foreach ($this->getTransitiveCalleeKeys($deadMethodKey, $callGraph) as $transitiveMethodKey) {
                if (!isset($deadMethods[$transitiveMethodKey])) {
                    continue;
                }

                $deadGroups[$deadMethodKey][] = $transitiveMethodKey;
                $methodsGrouped[$transitiveMethodKey] = true;
            }
        }

        // now only cycles remain, lets pick group representatives based on first occurrence
        foreach ($deadMethods as $deadMethodKey => $_) {
            if (isset($methodsGrouped[$deadMethodKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveCalleeKeys($deadMethodKey, $callGraph);

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

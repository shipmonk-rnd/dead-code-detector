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

    public function __construct(
        ClassHierarchy $classHierarchy
    )
    {
        $this->classHierarchy = $classHierarchy;
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

        $declaredMethods = [];

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
                $declaredMethods[$definition] = [$file, $methodData['line']];
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

                    foreach ($this->getMethodsToMarkAsUsed($call) as $methodDefinitionToMarkAsUsed) {
                        unset($declaredMethods[$methodDefinitionToMarkAsUsed]);
                    }
                }
            }
        }

        unset($methodCallData);

        foreach ($entrypointData as $file => $entrypointsInFile) {
            foreach ($entrypointsInFile as $entrypoints) {
                foreach ($entrypoints as $entrypoint) {
                    $call = Call::fromString($entrypoint);

                    foreach ($this->getMethodsToMarkAsUsed($call) as $methodDefinition) {
                        unset($declaredMethods[$methodDefinition]);
                    }
                }
            }
        }

        $errors = [];

        foreach ($declaredMethods as $definitionString => [$file, $line]) {
            $errors[] = $this->buildError($definitionString, $file, $line);
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
    private function getMethodsToMarkAsUsed(Call $call): array
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

    private function buildError(
        string $methodKey,
        string $file,
        int $line
    ): IdentifierRuleError
    {
        return RuleErrorBuilder::message('Unused ' . $methodKey)
            ->file($file)
            ->line($line)
            ->identifier('shipmonk.deadMethod')
            ->build();
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

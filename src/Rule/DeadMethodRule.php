<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\MethodDefinition;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Reflection\ClassHierarchy;
use function array_keys;
use function array_merge;
use function array_values;
use function in_array;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule
{

    private ReflectionProvider $reflectionProvider;

    private ClassHierarchy $classHierarchy; // TODO not reflection namespace

    /**
     * @var array<string, IdentifierRuleError>
     */
    private array $errors = [];

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      methods: array<string, array{line: int}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * @var list<EntrypointProvider>
     */
    private array $entrypointProviders;

    /**
     * @param list<EntrypointProvider> $entrypointProviders
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        ClassHierarchy $classHierarchy,
        array $entrypointProviders
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->classHierarchy = $classHierarchy;
        $this->entrypointProviders = $entrypointProviders;
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

        $methodDeclarationData = $node->get(MethodDefinitionCollector::class); // TODO rename to ClassDefinitionCollector?
        $methodCallData = $node->get(MethodCallCollector::class);

        $declaredMethods = []; // TODO maintain old structure?

        foreach ($methodDeclarationData as $file => $data) {
            foreach ($data as $typeData) {
                $typeName = $typeData['name'];
                $this->typeDefinitions[$typeName] = [
                    'file' => $file,
                    ...$typeData,
                ];
            }
        }

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitUsages($typeName, $this->getTraitUsages($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $definition = new MethodDefinition($typeName, $methodName);
                $declaredMethods[$definition->toString()] = [$file, $methodData['line']];

                $this->fillMethodHierarchy($typeName, $methodName, $ancestorNames);
            }
        }

        unset($methodDeclarationData);

        foreach ($methodCallData as $file => $callsInFile) {
            foreach ($callsInFile as $calls) {
                foreach ($calls as $callString) {
                    $call = Call::fromString($callString);

                    if ($this->isAnonymousClass($call)) {
                        continue;
                    }

                    foreach ($this->getMethodsToMarkAsUsed($call) as $methodDefinitionToMarkAsUsed) {
                        unset($declaredMethods[$methodDefinitionToMarkAsUsed->toString()]);
                    }
                }
            }
        }

        unset($methodCallData);

        foreach ($declaredMethods as $definitionString => [$file, $line]) {
            $definition = MethodDefinition::fromString($definitionString);

            if ($this->isEntryPoint($definition)) {
                continue;
            }

            $this->raiseError($definition, $file, $line);
        }

        return array_values($this->errors);
    }

    /**
     * @param list<string> $ancestorNames
     */
    private function fillMethodHierarchy(string $typeName, string $methodName, array $ancestorNames): void
    {
        foreach ($ancestorNames as $ancestorName) {
            if (isset($this->typeDefinitions[$ancestorName]['methods'][$methodName])) {
                $this->classHierarchy->registerMethodPair(
                    new MethodDefinition($ancestorName, $methodName),
                    new MethodDefinition($typeName, $methodName),
                );
            }

            $this->fillMethodHierarchy(
                $typeName,
                $methodName,
                $this->getAncestorNames($ancestorName),
            );
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     */
    private function fillTraitUsages(string $typeName, array $usedTraits): void
    {
        foreach ($usedTraits as $traitName => $adaptations) {
            $traitMethods = array_keys($this->typeDefinitions[$traitName]['methods'] ?? []);

            $excludedMethods = $adaptations['excluded'] ?? [];

            foreach ($traitMethods as $traitMethod) {
                if (isset($this->typeDefinitions[$typeName]['methods'][$traitMethod])) {
                    continue; // overridden trait method, thus not used
                }

                $declaringTraitMethodDefinition = new MethodDefinition($traitName, $traitMethod);
                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $aliasMethodDefinition = new MethodDefinition($typeName, $aliasMethodName);
                    $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodDefinition, $aliasMethodDefinition);
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $usedTraitMethodDefinition = new MethodDefinition($typeName, $traitMethod);
                $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodDefinition, $usedTraitMethodDefinition);
            }

            $this->fillTraitUsages($typeName, $this->getTraitUsages($traitName));
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

    private function isAnonymousClass(Call $call): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return strpos($call->className, 'AnonymousClass') === 0;
    }

    /**
     * @return list<MethodDefinition>
     */
    private function getMethodsToMarkAsUsed(Call $call): array
    {
        $definition = $call->getDefinition();

        $result = [$definition];

        if ($call->possibleDescendantCall) {
            foreach ($this->classHierarchy->getClassDescendants($definition->className) as $descendantName) {
                $result[] = new MethodDefinition($descendantName, $definition->methodName);
            }
        }

        // each descendant can be a trait user
        foreach ($result as $methodDefinition) {
            $traitMethodDefinition = $this->classHierarchy->getDeclaringTraitMethodDefinition($methodDefinition);

            if ($traitMethodDefinition !== null) {
                $result = array_merge(
                    $result,
                    [$traitMethodDefinition],
                    $this->classHierarchy->getMethodTraitUsages($traitMethodDefinition),
                );
            }
        }

        return $result;
    }

    private function raiseError(
        MethodDefinition $methodDefinition,
        string $file,
        int $line
    ): void
    {
        $declaringTraitMethodDefinition = $this->classHierarchy->getDeclaringTraitMethodDefinition($methodDefinition);

        if ($declaringTraitMethodDefinition !== null) {
            $declaringTraitReflection = $this->reflectionProvider->getClass($declaringTraitMethodDefinition->className)->getNativeReflection();
            $declaringTraitMethodKey = $declaringTraitMethodDefinition->toString();

            $this->errors[$declaringTraitMethodKey] = RuleErrorBuilder::message("Unused {$declaringTraitMethodKey}")
                ->file($declaringTraitReflection->getFileName()) // @phpstan-ignore-line
                ->line($declaringTraitReflection->getMethod($methodDefinition->methodName)->getStartLine()) // @phpstan-ignore-line
                ->identifier('shipmonk.deadMethod')
                ->build();

        } else {
            $this->errors[$methodDefinition->toString()] = RuleErrorBuilder::message('Unused ' . $methodDefinition->toString())
                ->file($file)
                ->line($line)
                ->identifier('shipmonk.deadMethod')
                ->build();
        }
    }

    private function isEntryPoint(MethodDefinition $methodDefinition): bool
    {
        if (!$this->reflectionProvider->hasClass($methodDefinition->className)) {
            return false;
        }

        $reflection = $this->reflectionProvider->getClass($methodDefinition->className);

        // if trait has users, we need to check entrypoint even from their context
        if ($reflection->isTrait()) {
            foreach ($this->classHierarchy->getMethodTraitUsages($methodDefinition) as $traitUsage) {
                if ($this->isEntryPoint($traitUsage)) {
                    return true;
                }
            }
        }

        // @phpstan-ignore missingType.checkedException (method should exist)
        $methodReflection = $reflection
            ->getNativeReflection()
            ->getMethod($methodDefinition->methodName);

        foreach ($this->entrypointProviders as $entrypointProvider) {
            if ($entrypointProvider->isEntrypoint($methodReflection)) {
                return true;
            }
        }

        return false;
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
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

}

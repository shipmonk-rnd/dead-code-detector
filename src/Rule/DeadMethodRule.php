<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\MethodDefinition;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Reflection\ClassHierarchy;
use function array_map;
use function array_merge;
use function array_values;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadMethodRule implements Rule
{

    private ReflectionProvider $reflectionProvider;

    private ClassHierarchy $classHierarchy;

    /**
     * @var array<string, IdentifierRuleError>
     */
    private array $errors = [];

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

        $classDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodDeclarationData = $node->get(MethodDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);

        $declaredMethods = [];

        foreach ($classDeclarationData as $file => $classesInFile) {
            foreach ($classesInFile as $classPairs) {
                foreach ($classPairs as $ancestor => $descendant) {
                    $this->classHierarchy->registerClassPair($ancestor, $descendant);
                }
            }
        }

        unset($classDeclarationData);

        foreach ($methodDeclarationData as $file => $methodsInFile) {
            foreach ($methodsInFile as $declared) {
                foreach ($declared as $serializedMethodDeclaration) {
                    [
                        'line' => $line,
                        'definition' => $definition,
                        'overriddenDefinitions' => $overriddenDefinitions,
                        'traitOriginDefinition' => $declaringTraitMethodKey,
                    ] = $this->deserializeMethodDeclaration($serializedMethodDeclaration);

                    $declaredMethods[$definition->toString()] = [$file, $line];

                    if ($declaringTraitMethodKey !== null) {
                        $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodKey, $definition);
                    }

                    foreach ($overriddenDefinitions as $ancestor) {
                        $this->classHierarchy->registerMethodPair($ancestor, $definition);
                    }
                }
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
            foreach ($this->classHierarchy->getMethodDescendants($definition) as $descendant) {
                $result[] = $descendant;
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

        $methodReflection = $this->reflectionProvider // @phpstan-ignore missingType.checkedException (method should exist)
            ->getClass($methodDefinition->className)
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
     * @param array{line: int, definition: string, overriddenDefinitions: list<string>, traitOriginDefinition: string|null} $serializedMethodDeclaration
     * @return array{line: int, definition: MethodDefinition, overriddenDefinitions: list<MethodDefinition>, traitOriginDefinition: MethodDefinition|null}
     */
    private function deserializeMethodDeclaration(array $serializedMethodDeclaration): array
    {
        return [
            'line' => $serializedMethodDeclaration['line'],
            'definition' => MethodDefinition::fromString($serializedMethodDeclaration['definition']),
            'overriddenDefinitions' => array_map(
                static fn (string $definition) => MethodDefinition::fromString($definition),
                $serializedMethodDeclaration['overriddenDefinitions'],
            ),
            'traitOriginDefinition' => $serializedMethodDeclaration['traitOriginDefinition'] !== null
                ? MethodDefinition::fromString($serializedMethodDeclaration['traitOriginDefinition'])
                : null,
        ];
    }

}

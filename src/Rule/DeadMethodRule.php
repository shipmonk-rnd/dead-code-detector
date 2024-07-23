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
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Reflection\ClassHierarchy;
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
            foreach ($classesInFile as $classes) {
                foreach ($classes as $declaredClassName) {
                    $this->registerClassToHierarchy($declaredClassName);
                }
            }
        }

        foreach ($methodDeclarationData as $file => $methodsInFile) {
            foreach ($methodsInFile as $declared) {
                foreach ($declared as [$declaredMethodKey, $line]) {
                    if ($this->isAnonymousClass($declaredMethodKey)) {
                        continue;
                    }

                    $declaredMethods[$declaredMethodKey] = [$file, $line];

                    $this->fillHierarchy($declaredMethodKey);
                }
            }
        }

        unset($methodDeclarationData);

        foreach ($methodCallData as $file => $callsInFile) {
            foreach ($callsInFile as $calls) {
                foreach ($calls as $calledMethodKey) {
                    if ($this->isAnonymousClass($calledMethodKey)) {
                        continue;
                    }

                    foreach ($this->getMethodsToMarkAsUsed($calledMethodKey) as $methodKeyToMarkAsUsed) {
                        unset($declaredMethods[$methodKeyToMarkAsUsed]);
                    }
                }
            }
        }

        unset($methodCallData);

        foreach ($declaredMethods as $methodKey => [$file, $line]) {
            if ($this->isEntryPoint($methodKey)) {
                continue;
            }

            $this->raiseError($methodKey, $file, $line);
        }

        return array_values($this->errors);
    }

    private function isAnonymousClass(string $methodKey): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return strpos($methodKey, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getMethodsToMarkAsUsed(string $methodKey): array
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);

        if (!$this->reflectionProvider->hasClass($classAndMethod->className)) {
            return []; // e.g. attributes
        }

        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);
        $traitMethodKey = DeadCodeHelper::getDeclaringTraitMethodKey($reflection, $classAndMethod->methodName);

        $result = array_merge(
            $this->getDescendantsToMarkAsUsed($methodKey),
            $this->getTraitUsersToMarkAsUsed($traitMethodKey),
        );

        foreach ($reflection->getAncestors() as $ancestor) {
            if (!$ancestor->hasMethod($classAndMethod->methodName)) {
                continue;
            }

            $ancestorMethodKey = DeadCodeHelper::composeMethodKey($ancestor->getName(), $classAndMethod->methodName);
            $result[] = $ancestorMethodKey;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getTraitUsersToMarkAsUsed(?string $traitMethodKey): array
    {
        if ($traitMethodKey === null) {
            return [];
        }

        return $this->classHierarchy->getMethodTraitUsages($traitMethodKey);
    }

    /**
     * @return list<string>
     */
    private function getDescendantsToMarkAsUsed(string $methodKey): array
    {
        return $this->classHierarchy->getMethodDescendants($methodKey);
    }

    private function fillHierarchy(string $methodKey): void
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);

        $declaringTraitMethodKey = DeadCodeHelper::getDeclaringTraitMethodKey($reflection, $classAndMethod->methodName);

        if ($declaringTraitMethodKey !== null) {
            $this->classHierarchy->registerMethodTraitUsage($declaringTraitMethodKey, $methodKey);
        }

        foreach ($reflection->getAncestors() as $ancestor) {
            if ($ancestor === $reflection) {
                continue;
            }

            if (!$ancestor->hasMethod($classAndMethod->methodName)) {
                continue;
            }

            if ($ancestor->isTrait()) {
                continue;
            }

            $ancestorMethodKey = DeadCodeHelper::composeMethodKey($ancestor->getName(), $classAndMethod->methodName);
            $this->classHierarchy->registerMethodPair($ancestorMethodKey, $methodKey);
        }
    }

    private function raiseError(
        string $methodKey,
        string $file,
        int $line
    ): void
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);
        $reflection = $this->reflectionProvider->getClass($classAndMethod->className);
        $declaringTraitReflection = DeadCodeHelper::getDeclaringTraitReflection($reflection, $classAndMethod->methodName);

        if ($declaringTraitReflection !== null) {
            $traitMethodKey = DeadCodeHelper::composeMethodKey($declaringTraitReflection->getName(), $classAndMethod->methodName);

            $this->errors[$traitMethodKey] = RuleErrorBuilder::message("Unused {$traitMethodKey}")
                ->file($declaringTraitReflection->getFileName()) // @phpstan-ignore-line
                ->line($declaringTraitReflection->getMethod($classAndMethod->methodName)->getStartLine()) // @phpstan-ignore-line
                ->identifier('shipmonk.deadMethod')
                ->build();

        } else {
            $this->errors[$methodKey] = RuleErrorBuilder::message("Unused $methodKey")
                ->file($file)
                ->line($line)
                ->identifier('shipmonk.deadMethod')
                ->build();
        }
    }

    private function isEntryPoint(string $methodKey): bool
    {
        $classAndMethod = DeadCodeHelper::splitMethodKey($methodKey);

        if (!$this->reflectionProvider->hasClass($classAndMethod->className)) {
            return false;
        }

        $methodReflection = $this->reflectionProvider // @phpstan-ignore missingType.checkedException (method should exist)
            ->getClass($classAndMethod->className)
            ->getNativeReflection()
            ->getMethod($classAndMethod->methodName);

        foreach ($this->entrypointProviders as $entrypointProvider) {
            if ($entrypointProvider->isEntrypoint($methodReflection)) {
                return true;
            }
        }

        return false;
    }

    private function registerClassToHierarchy(string $className): void
    {
        if ($this->reflectionProvider->hasClass($className)) {
            $origin = $this->reflectionProvider->getClass($className);

            foreach ($origin->getAncestors() as $ancestor) {
                if ($ancestor->isTrait() || $ancestor === $origin) {
                    continue;
                }

                $this->classHierarchy->registerClassPair($ancestor, $origin);
            }
        }
    }

}

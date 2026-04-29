<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function str_starts_with;

final class TemplateViewDataTraverser
{

    private readonly ReflectionProvider $reflectionProvider;

    /**
     * @var list<string>
     */
    private readonly array $analysedPaths;

    /**
     * @param list<string> $analysedPaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        array $analysedPaths,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->analysedPaths = $analysedPaths;
    }

    /**
     * Traverses referenced class names recursively, marking all public methods and properties as used.
     * Used by template engine providers (Twig, Blade) to handle data passed to views.
     *
     * @param list<string> $referencedClassNames
     * @param non-empty-string $rootContext
     * @return list<ClassMemberUsage>
     */
    public function getUsages(
        array $referencedClassNames,
        string $rootContext,
        MemberUsageProvider $provider,
    ): array
    {
        $usages = [];
        $visited = [];

        foreach ($referencedClassNames as $className) {
            $usages = [
                ...$usages,
                ...$this->traverseClassNameRecursively($className, $visited, $rootContext, $provider),
            ];
        }

        return $usages;
    }

    /**
     * @param non-empty-string $context
     * @param array<string, true> $visited
     * @return list<ClassMemberUsage>
     */
    private function traverseClassNameRecursively(
        string $className,
        array &$visited,
        string $context,
        MemberUsageProvider $provider,
    ): array
    {
        if (isset($visited[$className])) {
            return []; // Cycle detection
        }

        $visited[$className] = true;

        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        if ($this->shouldSkipClass($classReflection)) {
            return [];
        }

        return $this->getPublicMembersUsages($classReflection, $visited, $context, $provider);
    }

    /**
     * @param array<string, true> $visited
     * @param non-empty-string $context
     * @return list<ClassMemberUsage>
     */
    private function getPublicMembersUsages(
        ClassReflection $classReflection,
        array &$visited,
        string $context,
        MemberUsageProvider $provider,
    ): array
    {
        $usages = [];
        $className = $classReflection->getName();
        $nativeReflection = $classReflection->getNativeReflection();
        $shortClassName = $nativeReflection->getShortName();

        // Process public methods
        foreach ($nativeReflection->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            // Skip magic methods
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            // Skip methods declared outside analysed paths (vendor base classes/traits)
            $declaringFile = $method->getDeclaringClass()->getFileName();

            if (!$this->isFileAnalysed($declaringFile === false ? null : $declaringFile)) {
                continue;
            }

            // Mark method as used
            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($provider, VirtualUsageData::withNote($context)),
                new ClassMethodRef($className, $method->getName(), possibleDescendant: false),
            );

            // Traverse method return type
            $extendedMethodReflection = $classReflection->getNativeMethod($method->getName());
            $newContext = "{$context} -> {$shortClassName}::{$method->getName()}";

            foreach ($extendedMethodReflection->getVariants() as $variant) {
                foreach ($variant->getReturnType()->getReferencedClasses() as $returnClassName) {
                    $usages = [
                        ...$usages,
                        ...$this->traverseClassNameRecursively(
                            $returnClassName,
                            $visited,
                            $newContext,
                            $provider,
                        ),
                    ];
                }
            }
        }

        // Process public properties
        foreach ($nativeReflection->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            // Skip properties declared outside analysed paths (vendor base classes/traits)
            $declaringFile = $property->getDeclaringClass()->getFileName();

            if (!$this->isFileAnalysed($declaringFile === false ? null : $declaringFile)) {
                continue;
            }

            $usages[] = new ClassPropertyUsage(
                UsageOrigin::createVirtual($provider, VirtualUsageData::withNote($context)),
                new ClassPropertyRef($className, $property->getName(), possibleDescendant: false),
                AccessType::READ,
            );

            $propertyReflection = $classReflection->getNativeProperty($property->getName());
            $newContext = "{$context} -> {$shortClassName}::\${$property->getName()}";

            foreach ($propertyReflection->getReadableType()->getReferencedClasses() as $propertyClassName) {
                $usages = [
                    ...$usages,
                    ...$this->traverseClassNameRecursively(
                        $propertyClassName,
                        $visited,
                        $newContext,
                        $provider,
                    ),
                ];
            }
        }

        return $usages;
    }

    private function shouldSkipClass(ClassReflection $classReflection): bool
    {
        return !$this->isFileAnalysed($classReflection->getFileName());
    }

    private function isFileAnalysed(?string $fileName): bool
    {
        if ($fileName === null) {
            return false;
        }

        foreach ($this->analysedPaths as $path) {
            if (str_starts_with($fileName, $path)) {
                return true;
            }
        }

        return false;
    }

}

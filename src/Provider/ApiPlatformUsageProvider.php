<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionAttribute;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function explode;
use function is_string;
use function str_contains;
use function str_starts_with;
use function strlen;

final class ApiPlatformUsageProvider implements MemberUsageProvider
{

    private const RESOURCE_ATTRIBUTE = 'ApiPlatform\Metadata\ApiResource';

    private const HTTP_OPERATION_ATTRIBUTE = 'ApiPlatform\Metadata\HttpOperation';

    private const GRAPHQL_OPERATION_ATTRIBUTE = 'ApiPlatform\Metadata\GraphQl\Operation';

    private const API_FILTER_ATTRIBUTE = 'ApiPlatform\Metadata\ApiFilter';

    private readonly ReflectionProvider $reflectionProvider;

    private readonly bool $enabled;

    public function __construct(
        ReflectionProvider $reflectionProvider,
        ?bool $enabled,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled ?? $this->isApiPlatformInstalled();
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        if (!$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        if ($this->isApiResource($nativeReflection)) {
            $usages = [
                ...$usages,
                ...$this->emitResourceMemberUsages($classReflection),
                ...$this->emitOperationTargetUsages($nativeReflection),
            ];
        }

        $usages = [
            ...$usages,
            ...$this->emitApiFilterUsages($nativeReflection),
        ];

        return $usages;
    }

    /**
     * @param ReflectionClass|ReflectionEnum $class
     */
    private function isApiResource(object $class): bool
    {
        if ($this->hasAttribute($class, self::RESOURCE_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        if ($this->hasAttribute($class, self::HTTP_OPERATION_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        return $this->hasAttribute($class, self::GRAPHQL_OPERATION_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function emitResourceMemberUsages(ClassReflection $classReflection): array
    {
        $nativeReflection = $classReflection->getNativeReflection();
        $usages = [];
        $note = 'API Platform resource — serialized/deserialized by framework';

        if ($classReflection->hasNativeMethod('__construct')) {
            $constructorDeclaringClass = $classReflection->getNativeMethod('__construct')->getDeclaringClass()->getName();
            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef($constructorDeclaringClass, '__construct', possibleDescendant: false),
            );
        }

        foreach ($nativeReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($property->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $usages[] = $this->createPropertyUsage($property, $note, AccessType::READ);
            $usages[] = $this->createPropertyUsage($property, $note, AccessType::WRITE);
        }

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->isStatic() || !$method->isPublic()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            if (!$this->isPropertyAccessorMethod($method->getName())) {
                continue;
            }

            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef($nativeReflection->getName(), $method->getName(), possibleDescendant: false),
            );
        }

        return $usages;
    }

    /**
     * @param ReflectionClass|ReflectionEnum $class
     * @return list<ClassMemberUsage>
     */
    private function emitOperationTargetUsages(object $class): array
    {
        $usages = [];

        $attributes = [
            ...$class->getAttributes(self::RESOURCE_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF),
            ...$class->getAttributes(self::HTTP_OPERATION_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF),
            ...$class->getAttributes(self::GRAPHQL_OPERATION_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF),
        ];

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $attributeName = $attribute->getName();

            $provider = $arguments['provider'] ?? null;

            if (is_string($provider)) {
                foreach ($this->resolveTargetUsages($provider, 'provide', "Referenced as provider in #[$attributeName]") as $usage) {
                    $usages[] = $usage;
                }
            }

            $processor = $arguments['processor'] ?? null;

            if (is_string($processor)) {
                foreach ($this->resolveTargetUsages($processor, 'process', "Referenced as processor in #[$attributeName]") as $usage) {
                    $usages[] = $usage;
                }
            }

            $controller = $arguments['controller'] ?? null;

            if (is_string($controller)) {
                foreach ($this->resolveTargetUsages($controller, '__invoke', "Referenced as controller in #[$attributeName]") as $usage) {
                    $usages[] = $usage;
                }
            }
        }

        return $usages;
    }

    /**
     * @param ReflectionClass|ReflectionEnum $class
     * @return list<ClassMemberUsage>
     */
    private function emitApiFilterUsages(object $class): array
    {
        $usages = [];

        $attributes = [...$class->getAttributes(self::API_FILTER_ATTRIBUTE)];

        foreach ($class->getProperties() as $property) {
            foreach ($property->getAttributes(self::API_FILTER_ATTRIBUTE) as $propertyAttribute) {
                $attributes[] = $propertyAttribute;
            }
        }

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $filterClass = $arguments['filterClass'] ?? $arguments[0] ?? null;

            if (!is_string($filterClass) || !$this->reflectionProvider->hasClass($filterClass)) {
                continue;
            }

            $filterReflection = $this->reflectionProvider->getClass($filterClass);

            if (!$filterReflection->hasNativeMethod('__construct')) {
                continue;
            }

            $constructorDeclaringClass = $filterReflection->getNativeMethod('__construct')->getDeclaringClass()->getName();

            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('API Platform filter (instantiated by DIC via #[ApiFilter])')),
                new ClassMethodRef($constructorDeclaringClass, '__construct', possibleDescendant: false),
            );
        }

        return $usages;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function resolveTargetUsages(
        string $target,
        string $defaultMethod,
        string $note,
    ): array
    {
        $className = $target;
        $methodName = $defaultMethod;

        if (str_contains($target, '::')) {
            [$className, $methodName] = explode('::', $target, 2); // @phpstan-ignore offsetAccess.notFound
        }

        if ($className === '' || !$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        if (!$classReflection->hasNativeMethod($methodName)) {
            return [];
        }

        $usages = [
            new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef($className, $methodName, possibleDescendant: true),
            ),
        ];

        if ($classReflection->hasNativeMethod('__construct')) {
            $constructorDeclaringClass = $classReflection->getNativeMethod('__construct')->getDeclaringClass()->getName();

            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef($constructorDeclaringClass, '__construct', possibleDescendant: false),
            );
        }

        return $usages;
    }

    private function createPropertyUsage(
        ReflectionProperty $property,
        string $note,
        AccessType $accessType,
    ): ClassPropertyUsage
    {
        return new ClassPropertyUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassPropertyRef(
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                possibleDescendant: false,
            ),
            $accessType,
        );
    }

    private function isPropertyAccessorMethod(string $methodName): bool
    {
        foreach (['get', 'set', 'is', 'has', 'can', 'add', 'remove'] as $prefix) {
            if (
                str_starts_with($methodName, $prefix)
                && isset($methodName[strlen($prefix)])
                && $methodName[strlen($prefix)] >= 'A'
                && $methodName[strlen($prefix)] <= 'Z'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass|ReflectionEnum|ReflectionMethod|ReflectionProperty $reflector
     * @param ReflectionAttribute::IS_*|0 $flags
     */
    private function hasAttribute(
        object $reflector,
        string $attributeClass,
        int $flags = 0,
    ): bool
    {
        if ($reflector->getAttributes($attributeClass) !== []) {
            return true;
        }

        if ($flags === 0) {
            return false;
        }

        try {
            /** @throws IdentifierNotFound */
            return $reflector->getAttributes($attributeClass, $flags) !== [];
        } catch (IdentifierNotFound $e) {
            return false;
        }
    }

    private function isApiPlatformInstalled(): bool
    {
        return InstalledVersions::isInstalled('api-platform/core')
            || InstalledVersions::isInstalled('api-platform/metadata')
            || InstalledVersions::isInstalled('api-platform/state')
            || InstalledVersions::isInstalled('api-platform/symfony');
    }

}

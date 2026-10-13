<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use JMS\Serializer\Annotation\Accessor;
use JMS\Serializer\Annotation\AccessType;
use JMS\Serializer\Annotation\DeprecatedReadOnly;
use JMS\Serializer\Annotation\Exclude;
use JMS\Serializer\Annotation\ExclusionPolicy;
use JMS\Serializer\Annotation\Expose;
use JMS\Serializer\Annotation\PostDeserialize;
use JMS\Serializer\Annotation\PostSerialize;
use JMS\Serializer\Annotation\PreSerialize;
use JMS\Serializer\Annotation\ReadOnlyProperty;
use JMS\Serializer\Annotation\VirtualProperty;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Naming\CaseInsensitiveName;
use function array_key_exists;
use function is_array;
use function is_string;
use function str_replace;
use function strrpos;
use function strtoupper;
use function substr;

final class JmsSerializerUsageProvider implements ActivatableUsageProvider
{

    /**
     * Values of JMS\Serializer\GraphNavigatorInterface::DIRECTION_*
     */
    private const DIRECTION_SERIALIZATION = 1;
    private const DIRECTION_DESERIALIZATION = 2;

    /**
     * Value of JMS\Serializer\Metadata\PropertyMetadata::ACCESS_TYPE_PUBLIC_METHOD
     */
    private const ACCESS_TYPE_PUBLIC_METHOD = 'public_method';

    private const READ_ONLY_ATTRIBUTES = [
        ReadOnlyProperty::class,
        DeprecatedReadOnly::class,
        'JMS\\Serializer\\Annotation\\ReadOnly', // class_alias of DeprecatedReadOnly
    ];

    private readonly bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('jms/serializer');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return $this->getUsagesFromMetadataAttributes($node);
        }

        if ($node instanceof Return_) {
            return $this->getUsagesOfSubscriber($node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfSubscriber(
        Return_ $node,
        Scope $scope,
    ): array
    {
        if ($node->expr === null) {
            return [];
        }

        if (!$scope->isInClass() || $scope->isInAnonymousFunction()) {
            return [];
        }

        $method = $scope->getFunction();

        if (!$method instanceof MethodReflection) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        $isEventSubscriber = CaseInsensitiveName::equals($method->getName(), 'getSubscribedEvents')
            && $classReflection->implementsInterface(EventSubscriberInterface::class);
        $isSubscribingHandler = CaseInsensitiveName::equals($method->getName(), 'getSubscribingMethods')
            && $classReflection->implementsInterface(SubscribingHandlerInterface::class);

        if (!$isEventSubscriber && !$isSubscribingHandler) {
            return [];
        }

        $usages = [];
        $usageOrigin = UsageOrigin::createRegular($node, $scope);

        foreach ($scope->getType($node->expr)->getIterableValueType()->getConstantArrays() as $entry) {
            $methodNames = $isEventSubscriber
                ? $this->getEventSubscriberMethodNames($entry)
                : $this->getSubscribingHandlerMethodNames($entry);

            foreach ($methodNames as $methodName) {
                $usages[] = new ClassMethodUsage(
                    $usageOrigin,
                    new ClassMethodRef(
                        $classReflection->getName(),
                        $methodName,
                        possibleDescendant: true,
                    ),
                );
            }
        }

        return $usages;
    }

    /**
     * Mirrors JMS\Serializer\EventDispatcher\EventDispatcher::addSubscriber()
     *
     * @return list<string>
     */
    private function getEventSubscriberMethodNames(ConstantArrayType $entry): array
    {
        $methodNames = $this->getConstantStrings($entry, 'method');

        if ($this->isOffsetSet($entry, 'method')->yes()) {
            return $methodNames;
        }

        foreach ($this->getConstantStrings($entry, 'event') as $eventName) {
            $methodNames[] = 'on' . str_replace(['_', '.'], '', $eventName);
        }

        return $methodNames;
    }

    /**
     * Mirrors JMS\Serializer\Handler\HandlerRegistry::registerSubscribingHandler()
     *
     * @return list<string>
     */
    private function getSubscribingHandlerMethodNames(ConstantArrayType $entry): array
    {
        $methodNames = $this->getConstantStrings($entry, 'method');

        if ($this->isOffsetSet($entry, 'method')->yes()) {
            return $methodNames;
        }

        $directions = $this->isOffsetSet($entry, 'direction')->yes()
            ? $entry->getOffsetValueType(new ConstantStringType('direction'))->getConstantScalarValues()
            : [self::DIRECTION_DESERIALIZATION, self::DIRECTION_SERIALIZATION];

        foreach ($this->getConstantStrings($entry, 'type') as $type) {
            $namespaceSeparatorPosition = strrpos($type, '\\');
            $shortType = $namespaceSeparatorPosition === false
                ? $type
                : substr($type, $namespaceSeparatorPosition + 1);

            foreach ($this->getConstantStrings($entry, 'format') as $format) {
                // mirrors JMS\Serializer\Handler\HandlerRegistry::getDefaultMethod()
                foreach ($directions as $direction) {
                    if ($direction === self::DIRECTION_DESERIALIZATION) {
                        $methodNames[] = 'deserialize' . $shortType . 'From' . $format;

                    } elseif ($direction === self::DIRECTION_SERIALIZATION) {
                        $methodNames[] = 'serialize' . $shortType . 'To' . $format;
                    }
                }
            }
        }

        return $methodNames;
    }

    /**
     * Mirrors isset($entry[$key])
     */
    private function isOffsetSet(
        ConstantArrayType $entry,
        string $key,
    ): TrinaryLogic
    {
        $offsetType = new ConstantStringType($key);
        $hasOffset = $entry->hasOffsetValueType($offsetType);

        if ($hasOffset->no()) {
            return TrinaryLogic::createNo();
        }

        return $hasOffset->and($entry->getOffsetValueType($offsetType)->isNull()->negate());
    }

    /**
     * @return list<string>
     */
    private function getConstantStrings(
        ConstantArrayType $entry,
        string $key,
    ): array
    {
        $offsetType = new ConstantStringType($key);

        if ($entry->hasOffsetValueType($offsetType)->no()) {
            return [];
        }

        $strings = [];

        foreach ($entry->getOffsetValueType($offsetType)->getConstantStrings() as $constantString) {
            $strings[] = $constantString->getValue();
        }

        return $strings;
    }

    /**
     * Mirrors JMS\Serializer\Metadata\Driver\AnnotationOrAttributeDriver::loadMetadataForClass()
     *
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromMetadataAttributes(InClassNode $node): array
    {
        $class = $node->getClassReflection()->getNativeReflection();
        $className = $class->getName();
        $usages = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if ($this->isCallbackMethod($method)) {
                $usages[] = $this->createUsage($className, $method->getName(), !$method->isPrivate(), 'Serialization callback via attribute');

            } elseif ($this->hasAttribute($method, VirtualProperty::class) && $this->isExposed($class, $method, isVirtual: true)) {
                $usages[] = $this->createUsage($className, $method->getName(), true, 'Getter of #[VirtualProperty]');
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if (!$this->isExposed($class, $property, isVirtual: false)) {
                continue;
            }

            $getter = $this->getAccessorGetter($class, $property);
            $setter = $this->getAccessorSetter($class, $property);

            if ($getter !== null) {
                $usages[] = $this->createUsage($className, $getter, true, "Getter of property \${$property->getName()}");
            }

            if ($setter !== null) {
                $usages[] = $this->createUsage($className, $setter, true, "Setter of property \${$property->getName()}");
            }
        }

        return $usages;
    }

    private function isCallbackMethod(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, PreSerialize::class)
            || $this->hasAttribute($method, PostSerialize::class)
            || $this->hasAttribute($method, PostDeserialize::class);
    }

    /**
     * Tells if the driver passes the member to ClassMetadata::addPropertyMetadata()
     */
    private function isExposed(
        ReflectionClass|ReflectionEnum $class,
        ReflectionMethod|ReflectionProperty $member,
        bool $isVirtual,
    ): bool
    {
        if ($this->isExcludedUnconditionally($class)) {
            return false;
        }

        if ($this->getExclusionPolicy($class) === 'ALL') {
            return $isVirtual || $this->hasAttribute($member, Expose::class);
        }

        return !$this->isExcludedUnconditionally($member);
    }

    private function isExcludedUnconditionally(ReflectionClass|ReflectionEnum|ReflectionMethod|ReflectionProperty $reflection): bool
    {
        foreach ($reflection->getAttributes(Exclude::class) as $attribute) {
            if ($this->getAttributeParameter($attribute->getArguments(), 'if') === null) {
                return true;
            }
        }

        return false;
    }

    private function getExclusionPolicy(ReflectionClass|ReflectionEnum $class): string
    {
        foreach ($class->getAttributes(ExclusionPolicy::class) as $attribute) {
            $policy = $this->getAttributeParameter($attribute->getArguments(), 'policy');

            if (is_string($policy)) {
                return strtoupper($policy);
            }
        }

        return 'NONE';
    }

    /**
     * Mirrors the getter that JMS\Serializer\Metadata\PropertyMetadata::setAccessor() selects
     */
    private function getAccessorGetter(
        ReflectionClass|ReflectionEnum $class,
        ReflectionProperty $property,
    ): ?string
    {
        $getter = $this->getAccessorParameter($property, 'getter');

        if ($getter !== null) {
            return $getter;
        }

        if ($this->getAccessType($class, $property) !== self::ACCESS_TYPE_PUBLIC_METHOD) {
            return null;
        }

        foreach (['get', 'is', 'has'] as $prefix) {
            if ($this->hasPublicMethod($class, $prefix . $property->getName())) {
                return $prefix . $property->getName();
            }
        }

        return null;
    }

    /**
     * Mirrors the setter that JMS\Serializer\Metadata\PropertyMetadata::setAccessor() selects.
     * DeserializationGraphNavigator does not write read-only properties.
     */
    private function getAccessorSetter(
        ReflectionClass|ReflectionEnum $class,
        ReflectionProperty $property,
    ): ?string
    {
        if ($this->isReadOnly($class, $property)) {
            return null;
        }

        $setter = $this->getAccessorParameter($property, 'setter');

        if ($setter !== null) {
            return $setter;
        }

        if ($this->getAccessType($class, $property) !== self::ACCESS_TYPE_PUBLIC_METHOD) {
            return null;
        }

        return $this->hasPublicMethod($class, 'set' . $property->getName())
            ? 'set' . $property->getName()
            : null;
    }

    private function getAccessorParameter(
        ReflectionProperty $property,
        string $parameterName,
    ): ?string
    {
        foreach ($property->getAttributes(Accessor::class) as $attribute) {
            $methodName = $this->getAttributeParameter($attribute->getArguments(), $parameterName, $parameterName === 'getter' ? 0 : 1);

            if (is_string($methodName) && $methodName !== '') {
                return $methodName;
            }
        }

        return null;
    }

    private function getAccessType(
        ReflectionClass|ReflectionEnum $class,
        ReflectionProperty $property,
    ): ?string
    {
        foreach ([$property, $class] as $reflection) {
            foreach ($reflection->getAttributes(AccessType::class) as $attribute) {
                $type = $this->getAttributeParameter($attribute->getArguments(), 'type');

                return is_string($type) ? $type : null;
            }
        }

        return null;
    }

    private function isReadOnly(
        ReflectionClass|ReflectionEnum $class,
        ReflectionProperty $property,
    ): bool
    {
        foreach (self::READ_ONLY_ATTRIBUTES as $attributeClass) {
            foreach ($property->getAttributes($attributeClass) as $attribute) {
                return $this->getAttributeParameter($attribute->getArguments(), 'readOnly') !== false;
            }
        }

        foreach (self::READ_ONLY_ATTRIBUTES as $attributeClass) {
            if ($this->hasAttribute($class, $attributeClass)) {
                return true;
            }
        }

        return false;
    }

    private function hasPublicMethod(
        ReflectionClass|ReflectionEnum $class,
        string $methodName,
    ): bool
    {
        foreach ($class->getMethods() as $method) {
            if ($method->isPublic() && CaseInsensitiveName::equals($method->getName(), $methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors JMS\Serializer\Annotation\AnnotationUtilsTrait::loadAnnotationParameters()
     *
     * @param array<int|string, mixed> $arguments
     * @param int $position Position among the constructor parameters that follow $values
     */
    private function getAttributeParameter(
        array $arguments,
        string $name,
        int $position = 0,
    ): mixed
    {
        $values = $arguments['values'] ?? $arguments[0] ?? [];

        if (!is_array($values)) {
            $values = ['value' => $values];
        }

        if ($position === 0 && array_key_exists('value', $values)) {
            return $values['value'];
        }

        if (array_key_exists($name, $values)) {
            return $values[$name];
        }

        return $arguments[$name] ?? $arguments[$position + 1] ?? null;
    }

    private function hasAttribute(
        ReflectionClass|ReflectionEnum|ReflectionMethod|ReflectionProperty $reflection,
        string $attributeClass,
    ): bool
    {
        return $reflection->getAttributes($attributeClass) !== [];
    }

    private function createUsage(
        string $className,
        string $methodName,
        bool $possibleDescendant,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                $possibleDescendant,
            ),
        );
    }

}

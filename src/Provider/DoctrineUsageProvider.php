<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function strpos;

final class DoctrineUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? $this->isDoctrineInstalled();
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getUsagesFromReflection($node, $scope),
            ];
        }

        if ($node instanceof Return_) {
            $usages = [
                ...$usages,
                ...$this->getUsagesOfEventSubscriber($node, $scope),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromReflection(
        InClassNode $node,
        Scope $scope
    ): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeReflection->getProperties() as $nativePropertyReflection) {
            $propertyName = $nativePropertyReflection->name;
            $propertyReflection = $classReflection->getProperty($propertyName, $scope);

            $usages = [
                ...$usages,
                ...$this->getUsagesOfEnumColumn($classReflection->getName(), $propertyReflection),
            ];

            $propertyUsageNote = $this->shouldMarkPropertyAsUsed($nativePropertyReflection);

            if ($propertyUsageNote !== null) {
                $usages[] = $this->createPropertyUsage($nativePropertyReflection, $propertyUsageNote);
            }
        }

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $usageNote = $this->shouldMarkMethodAsUsed($method);

            if ($usageNote !== null) {
                $usages[] = $this->createMethodUsage($classReflection->getNativeMethod($method->getName()), $usageNote);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfEventSubscriber(
        Return_ $node,
        Scope $scope
    ): array
    {
        if ($node->expr === null) {
            return [];
        }

        if (!$scope->isInClass()) {
            return [];
        }

        if (!$scope->getFunction() instanceof MethodReflection) {
            return [];
        }

        if ($scope->getFunction()->getName() !== 'getSubscribedEvents') {
            return [];
        }

        if (!$scope->getClassReflection()->implementsInterface('Doctrine\Common\EventSubscriber')) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        $usages = [];
        $usageOrigin = UsageOrigin::createRegular($node, $scope);

        foreach ($scope->getType($node->expr)->getConstantArrays() as $rootArray) {
            foreach ($rootArray->getValuesArray()->getValueTypes() as $eventConfig) {
                foreach ($eventConfig->getConstantStrings() as $subscriberMethodString) {
                    $usages[] = new ClassMethodUsage(
                        $usageOrigin,
                        new ClassMethodRef(
                            $className,
                            $subscriberMethodString->getValue(),
                            true,
                        ),
                    );
                }
            }
        }

        return $usages;
    }

    private function shouldMarkMethodAsUsed(ReflectionMethod $method): ?string
    {
        $methodName = $method->getName();
        $class = $method->getDeclaringClass();

        if ($this->isLifecycleEventMethod($method)) {
            return 'Lifecycle event method via attribute';
        }

        if ($this->isEntityRepositoryConstructor($class, $method)) {
            return 'Entity repository constructor (created by EntityRepositoryFactory)';
        }

        if ($this->isPartOfAsEntityListener($class, $methodName)) {
            return 'Is part of AsEntityListener methods';
        }

        if ($this->isPartOfAsDoctrineListener($class, $methodName)) {
            return 'Is part of AsDoctrineListener methods';
        }

        if ($this->isPartOfAutoconfigureTagDoctrineListener($class, $methodName)) {
            return 'Is part of AutoconfigureTag doctrine.event_listener methods';
        }

        if ($this->isProbablyDoctrineListener($methodName)) {
            return 'Is probable listener method';
        }

        return null;
    }

    private function shouldMarkPropertyAsUsed(ReflectionProperty $property): ?string
    {
        $attributes = $property->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();

            if (strpos($attributeName, 'Doctrine\ORM\Mapping\\') === 0) {
                return 'Doctrine ORM mapped property';
            }
        }

        return null;
    }

    private function isLifecycleEventMethod(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostLoad')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostPersist')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostUpdate')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreFlush')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PrePersist')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreRemove')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreUpdate');
    }

    /**
     * Ideally, we would need to parse DIC xml to know this for sure just like phpstan-symfony does.
     * - see Doctrine\ORM\Events::*
     */
    private function isProbablyDoctrineListener(string $methodName): bool
    {
        return $methodName === 'preRemove'
            || $methodName === 'postRemove'
            || $methodName === 'prePersist'
            || $methodName === 'postPersist'
            || $methodName === 'preUpdate'
            || $methodName === 'postUpdate'
            || $methodName === 'postLoad'
            || $methodName === 'loadClassMetadata'
            || $methodName === 'onClassMetadataNotFound'
            || $methodName === 'preFlush'
            || $methodName === 'onFlush'
            || $methodName === 'postFlush'
            || $methodName === 'onClear';
    }

    private function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function isPartOfAsEntityListener(
        ReflectionClass $class,
        string $methodName
    ): bool
    {
        foreach ($class->getAttributes('Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener') as $attribute) {
            $listenerMethodName = $attribute->getArguments()['method'] ?? $attribute->getArguments()[1] ?? null;

            if ($listenerMethodName === $methodName) {
                return true;
            }
        }

        return false;
    }

    private function isPartOfAsDoctrineListener(
        ReflectionClass $class,
        string $methodName
    ): bool
    {
        foreach ($class->getAttributes('Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener') as $attribute) {
            $eventName = $attribute->getArguments()['event'] ?? $attribute->getArguments()[0] ?? null;

            // AsDoctrineListener doesn't have a 'method' parameter
            // Symfony looks for a method named after the event, or falls back to __invoke
            if ($eventName === $methodName || $methodName === '__invoke') {
                return true;
            }
        }

        return false;
    }

    private function isPartOfAutoconfigureTagDoctrineListener(
        ReflectionClass $class,
        string $methodName
    ): bool
    {
        foreach ($class->getAttributes('Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag') as $attribute) {
            $arguments = $attribute->getArguments();
            $tagName = $arguments[0] ?? $arguments['name'] ?? null;

            // Only handle doctrine.event_listener tags
            if ($tagName !== 'doctrine.event_listener') {
                continue;
            }

            $listenerMethodName = $arguments['method'] ?? null;

            // If no method is specified, the listener method name is inferred from the event name
            if ($listenerMethodName === null) {
                $eventName = $arguments['event'] ?? null;

                if ($eventName === $methodName) {
                    return true;
                }
            } elseif ($listenerMethodName === $methodName) {
                return true;
            }
        }

        return false;
    }

    private function isEntityRepositoryConstructor(
        ReflectionClass $class,
        ReflectionMethod $method
    ): bool
    {
        if (!$method->isConstructor()) {
            return false;
        }

        return $class->isSubclassOf('Doctrine\ORM\EntityRepository');
    }

    private function isDoctrineInstalled(): bool
    {
        return InstalledVersions::isInstalled('doctrine/orm')
            || InstalledVersions::isInstalled('doctrine/event-manager')
            || InstalledVersions::isInstalled('doctrine/doctrine-bundle');
    }

    private function createMethodUsage(
        ExtendedMethodReflection $methodReflection,
        string $note
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

    private function createPropertyUsage(
        ReflectionProperty $propertyReflection,
        string $note
    ): ClassPropertyUsage
    {
        return new ClassPropertyUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassPropertyRef(
                $propertyReflection->getDeclaringClass()->getName(),
                $propertyReflection->getName(),
                false,
            ),
        );
    }

    /**
     * @return list<ClassConstantUsage>
     */
    private function getUsagesOfEnumColumn(
        string $className,
        ExtendedPropertyReflection $property
    ): array
    {
        $usages = [];
        $propertyName = $property->getName();

        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getName() !== 'Doctrine\ORM\Mapping\Column') {
                continue;
            }

            foreach ($attribute->getArgumentTypes() as $name => $type) {
                if ($name !== 'enumType') {
                    continue;
                }

                foreach ($type->getConstantStrings() as $constantString) {
                    $usages[] = new ClassConstantUsage(
                        UsageOrigin::createVirtual($this, VirtualUsageData::withNote("Used in enumType of #[Column] of $className::$propertyName")),
                        new ClassConstantRef(
                            $constantString->getValue(),
                            null,
                            false,
                            TrinaryLogic::createYes(),
                        ),
                    );
                }
            }
        }

        return $usages;
    }

}

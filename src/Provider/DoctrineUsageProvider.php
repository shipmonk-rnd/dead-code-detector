<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
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
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;

class DoctrineUsageProvider implements MemberUsageProvider
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
                ...$this->getUsagesOfEnumColumn($classReflection->getName(), $propertyName, $propertyReflection),
            ];
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

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?string
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

        if ($this->isProbablyDoctrineListener($methodName)) {
            return 'Is probable listener method';
        }

        return null;
    }

    protected function isLifecycleEventMethod(ReflectionMethod $method): bool
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
    protected function isProbablyDoctrineListener(string $methodName): bool
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

    protected function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    protected function isPartOfAsEntityListener(
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

    protected function isEntityRepositoryConstructor(
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

    /**
     * @return list<ClassConstantUsage>
     */
    private function getUsagesOfEnumColumn(
        string $className,
        string $propertyName,
        ExtendedPropertyReflection $property
    ): array
    {
        $usages = [];

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

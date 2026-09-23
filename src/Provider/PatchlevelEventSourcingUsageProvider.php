<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function str_starts_with;

final class PatchlevelEventSourcingUsageProvider implements ActivatableUsageProvider
{

    private readonly bool $enabled;

    public function __construct(
        ?bool $enabled,
    )
    {
        $this->enabled = $enabled ?? $this->isPatchlevelEventSourcingInstalled();
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getMethodUsagesFromReflection($node),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsagesFromReflection(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            $methodName = $method->getName();

            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $note = $this->shouldMarkAsUsed($method);

            if ($note !== null) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($methodName), $note);
            }
        }

        return $usages;
    }

    private function shouldMarkAsUsed(ReflectionMethod $method): ?string
    {
        if ($this->isMethodWithApplyAttribute($method)) {
            return 'Apply method on aggregate via #[Apply] attribute';
        }

        if ($this->isMethodWithHandleAttribute($method)) {
            return 'Handle method via #[Handle] attribute';
        }

        if ($this->isMethodWithAnswerAttribute($method)) {
            return 'Answer method via #[Answer] attribute';
        }

        if ($this->isMethodWithSubscribeAttribute($method)) {
            return 'Subscribe method via #[Subscribe] attribute';
        }

        if ($this->isMethodWithSetupAttributeOnSubscriber($method)) {
            return 'Setup method on subscriber via #[Setup] attribute';
        }

        if ($this->isMethodWithTeardownAttributeOnSubscriber($method)) {
            return 'Teardown method on subscriber via #[Teardown] attribute';
        }

        if ($this->isMethodWithCleanupAttributeOnSubscriber($method)) {
            return 'Cleanup method on subscriber via #[Cleanup] attribute';
        }

        if ($this->isMethodWithOnFailedAttributeOnSubscriber($method)) {
            return 'OnFailed method on subscriber via #[OnFailed] attribute';
        }

        return null;
    }

    private function isMethodWithApplyAttribute(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Apply')
            && $this->hasAttribute($class, 'Patchlevel\EventSourcing\Attribute\Aggregate');
    }

    private function isMethodWithHandleAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Handle');
    }

    private function isMethodWithAnswerAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Answer');
    }

    private function isMethodWithSubscribeAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Subscribe');
    }

    private function isMethodWithSetupAttributeOnSubscriber(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Setup')
            && $this->hasAttribute($class, 'Patchlevel\EventSourcing\Attribute\Subscriber');
    }

    private function isMethodWithTeardownAttributeOnSubscriber(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Teardown')
            && $this->hasAttribute($class, 'Patchlevel\EventSourcing\Attribute\Subscriber');
    }

    private function isMethodWithCleanupAttributeOnSubscriber(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\Cleanup')
            && $this->hasAttribute($class, 'Patchlevel\EventSourcing\Attribute\Subscriber');
    }

    private function isMethodWithOnFailedAttributeOnSubscriber(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($method, 'Patchlevel\EventSourcing\Attribute\OnFailed')
            && $this->hasAttribute($class, 'Patchlevel\EventSourcing\Attribute\Subscriber');
    }

    /**
     * @param ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionEnum $classOrMethod
     */
    private function hasAttribute(
        Reflector $classOrMethod,
        string $attributeClass,
    ): bool
    {
        return $classOrMethod->getAttributes($attributeClass) !== [];
    }

    private function isPatchlevelEventSourcingInstalled(): bool
    {
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (str_starts_with($package, 'patchlevel/event-sourcing')) {
                return true;
            }
        }

        return false;
    }

    private function createUsage(
        ExtendedMethodReflection $methodReflection,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                possibleDescendant: false,
            ),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

}

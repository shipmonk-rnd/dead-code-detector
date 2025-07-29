<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function strpos;

final class BehatUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(?bool $enabled)
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('behat/behat');
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled || !$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$classReflection->implementsInterface('Behat\Behat\Context\Context')) {
            return [];
        }

        $usages = [];
        $className = $classReflection->getName();

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $methodName = $method->getName();

            if ($method->isConstructor()) {
                $usages[] = $this->createUsage($className, $methodName, 'Behat context constructor');
            } elseif ($this->isBehatContextMethod($method)) {
                $usages[] = $this->createUsage($className, $methodName, 'Behat step definition or hook');
            }
        }

        return $usages;
    }

    private function isBehatContextMethod(ReflectionMethod $method): bool
    {
        return $this->hasAnnotation($method, '@Given')
            || $this->hasAnnotation($method, '@When')
            || $this->hasAnnotation($method, '@Then')
            || $this->hasAnnotation($method, '@BeforeScenario')
            || $this->hasAnnotation($method, '@AfterScenario')
            || $this->hasAnnotation($method, '@BeforeStep')
            || $this->hasAnnotation($method, '@AfterStep')
            || $this->hasAnnotation($method, '@BeforeSuite')
            || $this->hasAnnotation($method, '@AfterSuite')
            || $this->hasAnnotation($method, '@BeforeFeature')
            || $this->hasAnnotation($method, '@AfterFeature')
            || $this->hasAnnotation($method, '@Transform')
            || $this->hasAttribute($method, 'Behat\Step\Given')
            || $this->hasAttribute($method, 'Behat\Step\When')
            || $this->hasAttribute($method, 'Behat\Step\Then')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeScenario')
            || $this->hasAttribute($method, 'Behat\Hook\AfterScenario')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeStep')
            || $this->hasAttribute($method, 'Behat\Hook\AfterStep')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeSuite')
            || $this->hasAttribute($method, 'Behat\Hook\AfterSuite')
            || $this->hasAttribute($method, 'Behat\Hook\BeforeFeature')
            || $this->hasAttribute($method, 'Behat\Hook\AfterFeature')
            || $this->hasAttribute($method, 'Behat\Transformation\Transform');
    }

    private function hasAnnotation(
        ReflectionMethod $method,
        string $string
    ): bool
    {
        if ($method->getDocComment() === false) {
            return false;
        }

        return strpos($method->getDocComment(), $string) !== false;
    }

    private function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function createUsage(
        string $className,
        string $methodName,
        string $reason
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $className,
                $methodName,
                false,
            ),
        );
    }

}

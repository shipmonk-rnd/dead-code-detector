<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use ReflectionClassConstant;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_merge;

abstract class ReflectionBasedMemberUsageProvider implements MemberUsageProvider
{

    /**
     * @return list<ClassMemberUsage>
     */
    public function getUsages(Node $node, Scope $scope): array
    {
        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $classReflection = $node->getClassReflection();

            return array_merge(
                $this->getMethodUsages($classReflection),
                $this->getConstantUsages($classReflection),
            );
        }

        return [];
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        return false; // Expected to be overridden by subclasses.
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): bool
    {
        return false; // Expected to be overridden by subclasses.
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsages(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeClassReflection->getMethods() as $nativeMethodReflection) {
            if ($nativeMethodReflection->getDeclaringClass()->getName() !== $nativeClassReflection->getName()) {
                continue; // skip methods from ancestors
            }

            if ($this->shouldMarkMethodAsUsed($nativeMethodReflection)) {
                $usages[] = $this->createMethodUsage($nativeMethodReflection);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassConstantUsage>
     */
    private function getConstantUsages(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeClassReflection->getReflectionConstants() as $nativeConstantReflection) {
            if ($nativeConstantReflection->getDeclaringClass()->getName() !== $nativeClassReflection->getName()) {
                continue; // skip constants from ancestors
            }

            if ($this->shouldMarkConstantAsUsed($nativeConstantReflection)) {
                $usages[] = $this->createConstantUsage($nativeConstantReflection);
            }
        }

        return $usages;
    }

    private function createConstantUsage(ReflectionClassConstant $constantReflection): ClassConstantUsage
    {
        return new ClassConstantUsage(
            UsageOrigin::createVirtual($this),
            new ClassConstantRef(
                $constantReflection->getDeclaringClass()->getName(),
                $constantReflection->getName(),
                false,
            ),
        );
    }

    private function createMethodUsage(ReflectionMethod $methodReflection): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

}

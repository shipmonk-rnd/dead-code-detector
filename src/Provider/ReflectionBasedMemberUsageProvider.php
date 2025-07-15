<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\TrinaryLogic;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionEnumUnitCase;
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
    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $classReflection = $node->getClassReflection();

            return array_merge(
                $this->getMethodUsages($classReflection),
                $this->getConstantUsages($classReflection),
                $this->getEnumCaseUsages($classReflection),
            );
        }

        return [];
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        return null; // Expected to be overridden by subclasses.
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        return null; // Expected to be overridden by subclasses.
    }

    protected function shouldMarkEnumCaseAsUsed(ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        return null; // Expected to be overridden by subclasses.
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

            $usage = $this->shouldMarkMethodAsUsed($nativeMethodReflection);

            if ($usage !== null) {
                $usages[] = $this->createMethodUsage($nativeMethodReflection, $usage);
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

            if ($nativeConstantReflection->isEnumCase()) {
                continue; // handled separately
            }

            $usage = $this->shouldMarkConstantAsUsed($nativeConstantReflection);

            if ($usage !== null) {
                $usages[] = $this->createConstantUsage($nativeConstantReflection, $usage);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassConstantUsage>
     */
    private function getEnumCaseUsages(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        if (!$nativeClassReflection instanceof ReflectionEnum) {
            return [];
        }

        $usages = [];

        foreach ($nativeClassReflection->getCases() as $nativeEnumCaseReflection) {
            $usage = $this->shouldMarkEnumCaseAsUsed($nativeEnumCaseReflection);

            if ($usage !== null) {
                $usages[] = $this->createEnumCaseUsage($nativeEnumCaseReflection, $usage);
            }
        }

        return $usages;
    }

    private function createConstantUsage(
        ReflectionClassConstant $constantReflection,
        VirtualUsageData $data
    ): ClassConstantUsage
    {
        return new ClassConstantUsage(
            UsageOrigin::createVirtual($this, $data),
            new ClassConstantRef(
                $constantReflection->getDeclaringClass()->getName(),
                $constantReflection->getName(),
                false,
                TrinaryLogic::createNo(),
            ),
        );
    }

    private function createMethodUsage(
        ReflectionMethod $methodReflection,
        VirtualUsageData $data
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, $data),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

    private function createEnumCaseUsage(
        ReflectionEnumUnitCase $enumCaseReflection,
        VirtualUsageData $usage
    ): ClassConstantUsage
    {
        return new ClassConstantUsage(
            UsageOrigin::createVirtual($this, $usage),
            new ClassConstantRef(
                $enumCaseReflection->getDeclaringClass()->getName(),
                $enumCaseReflection->getName(),
                false,
                TrinaryLogic::createYes(),
            ),
        );
    }

}

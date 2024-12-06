<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use ReflectionClassConstant;

abstract class SimpleConstantUsageProvider implements ConstantUsageProvider
{

    public function getConstantUsages(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeClassReflection->getReflectionConstants() as $nativeConstantReflection) {
            if ($nativeConstantReflection->getDeclaringClass()->getName() !== $nativeClassReflection->getName()) {
                continue; // skip constants from ancestors
            }

            if ($this->shouldMarkConstantAsUsed($nativeConstantReflection)) {
                $usages[] = $nativeConstantReflection;
            }
        }

        return $usages;
    }

    abstract protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): bool;

}

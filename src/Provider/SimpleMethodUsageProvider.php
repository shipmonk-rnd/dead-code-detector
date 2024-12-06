<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use ReflectionMethod;

abstract class SimpleMethodUsageProvider implements MethodUsageProvider
{

    public function getMethodUsages(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeClassReflection->getMethods() as $nativeMethodReflection) {
            if ($nativeMethodReflection->getDeclaringClass()->getName() !== $nativeClassReflection->getName()) {
                continue; // skip methods from ancestors
            }

            if ($this->shouldMarkMethodAsUsed($nativeMethodReflection)) {
                $usages[] = $classReflection->getNativeMethod($nativeMethodReflection->getName());
            }
        }

        return $usages;
    }

    abstract protected function shouldMarkMethodAsUsed(ReflectionMethod $method): bool;

}

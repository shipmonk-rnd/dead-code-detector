<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use ReflectionMethod;

abstract class MethodBasedEntrypointProvider implements EntrypointProvider
{

    public function getEntrypoints(ClassReflection $classReflection): array
    {
        $nativeClassReflection = $classReflection->getNativeReflection();

        $entrypoints = [];

        foreach ($nativeClassReflection->getMethods() as $nativeMethodReflection) {
            if ($nativeMethodReflection->getDeclaringClass()->getName() !== $nativeClassReflection->getName()) {
                continue; // skip methods from ancestors
            }

            if ($this->isEntrypointMethod($nativeMethodReflection)) {
                $entrypoints[] = $classReflection->getNativeMethod($nativeMethodReflection->getName());
            }
        }

        return $entrypoints;
    }

    abstract protected function isEntrypointMethod(ReflectionMethod $method): bool;

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use ReflectionMethod;

abstract class MethodBasedEntrypointProvider implements EntrypointProvider
{

    public function getEntrypoints(ClassReflection $reflection): array
    {
        $nativeReflection = $reflection->getNativeReflection();

        $entrypoints = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            if ($this->isEntrypointMethod($method)) {
                $entrypoints[] = $reflection->getNativeMethod($method->getName());
            }
        }

        return $entrypoints;
    }

    // TODO use PHPStan's MethodReflection?
    abstract protected function isEntrypointMethod(ReflectionMethod $method): bool;

}

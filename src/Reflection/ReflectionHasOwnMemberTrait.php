<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Reflection;

use PHPStan\Reflection\ClassReflection;
use ReflectionException;

trait ReflectionHasOwnMemberTrait
{

    private function hasOwnMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (!$classReflection->hasMethod($methodName)) {
            return false;
        }

        try {
            return $classReflection->getNativeReflection()->getMethod($methodName)->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    private function hasOwnConstant(ClassReflection $classReflection, string $constantName): bool
    {
        $constantReflection = $classReflection->getNativeReflection()->getReflectionConstant($constantName);

        if ($constantReflection === false) {
            return false;
        }

        return $constantReflection->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
    }

    private function hasOwnProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (!$classReflection->hasProperty($propertyName)) {
            return false;
        }

        try {
            return $classReflection->getNativeReflection()->getProperty($propertyName)->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
        } catch (ReflectionException $e) {
            return false;
        }
    }

}

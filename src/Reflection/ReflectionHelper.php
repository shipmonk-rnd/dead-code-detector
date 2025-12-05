<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Reflection;

use PHPStan\Reflection\ClassReflection;
use ReflectionClassConstant;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

final class ReflectionHelper
{

    /**
     * @param ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member
     * @return 'constant'|'property'|'method'
     */
    public static function getMemberType(object $member): string
    {
        if ($member instanceof ReflectionClassConstant) {
            return 'constant';
        } elseif ($member instanceof ReflectionProperty) {
            return 'property';
        } else {
            return 'method';
        }
    }

    public static function hasOwnMethod(
        ClassReflection $classReflection,
        string $methodName
    ): bool
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

    public static function hasOwnConstant(
        ClassReflection $classReflection,
        string $constantName
    ): bool
    {
        $constantReflection = $classReflection->getNativeReflection()->getReflectionConstant($constantName);

        if ($constantReflection === false) {
            return false;
        }

        return !$constantReflection->isEnumCase() && $constantReflection->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
    }

    public static function hasOwnEnumCase(
        ClassReflection $classReflection,
        string $constantName
    ): bool
    {
        $constantReflection = $classReflection->getNativeReflection()->getReflectionConstant($constantName);

        if ($constantReflection === false) {
            return false;
        }

        return $constantReflection->isEnumCase() && $constantReflection->getBetterReflection()->getDeclaringClass()->getName() === $classReflection->getName();
    }

    public static function hasOwnProperty(
        ClassReflection $classReflection,
        string $propertyName
    ): bool
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

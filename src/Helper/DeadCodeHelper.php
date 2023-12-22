<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Helper;

use PHPStan\BetterReflection\Reflection\ReflectionClass;
use PHPStan\Reflection\ClassReflection;
use ReflectionException;
use ShipMonk\PHPStan\DeadCode\Crate\ClassAndMethod;
use function explode;

class DeadCodeHelper
{

    public static function composeMethodKey(
        string $className,
        string $methodName
    ): string
    {
        return "{$className}::{$methodName}";
    }

    public static function splitMethodKey(string $methodKey): ClassAndMethod
    {
        return new ClassAndMethod(...explode('::', $methodKey));
    }

    public static function getDeclaringTraitMethodKey(
        ClassReflection $classReflection,
        string $methodName
    ): ?string
    {
        try {
            $realDeclaringClass = $classReflection->getNativeReflection()->getMethod($methodName)->getBetterReflection()->getDeclaringClass();
        } catch (ReflectionException $e) {
            return null;
        }

        if ($realDeclaringClass->isTrait()) {
            return self::composeMethodKey($realDeclaringClass->getName(), $methodName);
        }

        return null;
    }

    public static function getDeclaringTraitReflection(
        ClassReflection $classReflection,
        string $methodName
    ): ?ReflectionClass
    {
        try {
            $realDeclaringClass = $classReflection->getNativeReflection()->getMethod($methodName)->getBetterReflection()->getDeclaringClass();
        } catch (ReflectionException $e) {
            return null;
        }

        if ($realDeclaringClass->isTrait()) {
            return $realDeclaringClass;
        }

        return null;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use ReflectionClassConstant;

/**
 * Extension point for marking class constants used based on custom reflection logic.
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyAppConstantUsageProvider
 *          tags:
 *              - shipmonk.deadCode.constantUsageProvider
 */
interface ConstantUsageProvider
{

    /**
     * @return list<ReflectionClassConstant>
     */
    public function getConstantUsages(ClassReflection $classReflection): array;

}

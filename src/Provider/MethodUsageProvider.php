<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;

/**
 * Extension point for marking methods as used based on custom reflection logic.
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyAppUsageProvider
 *          tags:
 *              - shipmonk.deadCode.methodUsageProvider
 */
interface MethodUsageProvider
{

    /**
     * @return list<MethodReflection>
     */
    public function getMethodUsages(ClassReflection $classReflection): array;

}

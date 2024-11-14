<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;

/**
 * Extension point for marking methods as entrypoints (not dead) based on custom reflection logic.
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyEntrypointProvider
 *          tags:
 *              - shipmonk.deadCode.entrypointProvider
 */
interface MethodEntrypointProvider
{

    /**
     * @return list<MethodReflection>
     */
    public function getEntrypoints(ClassReflection $classReflection): array;

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;

interface EntrypointProvider
{

    public const TAG_ENTRYPOINT_PROVIDER = 'shipmonk.deadCode.entrypointProvider';

    /**
     * @return list<MethodReflection>
     */
    public function getEntrypoints(ClassReflection $classReflection): array;

}

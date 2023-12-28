<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionMethod;

interface EntrypointProvider
{

    public const TAG_ENTRYPOINT_PROVIDER = 'shipmonk.deadCode.entrypointProvider';

    public function isEntrypoint(ReflectionMethod $method): bool;

}

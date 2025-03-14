<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

interface NeverReportedReason
{

    public const ABSTRACT_TRAIT_METHOD = 'abstract trait method';
    public const PRIVATE_CONSTRUCTOR_NO_PARAMS = 'private constructor without params';
    public const UNSUPPORTED_MAGIC_METHOD = 'unsupported magic method';

}

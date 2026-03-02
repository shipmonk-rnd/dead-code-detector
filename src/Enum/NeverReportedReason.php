<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

enum NeverReportedReason: string
{

    case ABSTRACT_TRAIT_METHOD = 'abstract trait method';
    case PRIVATE_CONSTRUCTOR_NO_PARAMS = 'private constructor without params';
    case UNSUPPORTED_MAGIC_METHOD = 'unsupported magic method';

}

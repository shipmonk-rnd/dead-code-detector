<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

enum MemberType: int
{

    case METHOD = 1;
    case CONSTANT = 2;
    case PROPERTY = 3;

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

enum AccessType: int
{

    case READ = 1;
    case WRITE = 2;

}

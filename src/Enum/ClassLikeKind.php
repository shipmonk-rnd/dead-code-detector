<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

enum ClassLikeKind: string
{

    case TRAIT = 'trait';
    case INTERFACE = 'interface';
    case CLASSS = 'class';
    case ENUM = 'enum';

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

interface Kind
{

    public const TRAIT = 'trait';
    public const INTERFACE = 'interface';
    public const CLASSS = 'class';
    public const ENUM = 'enum';

}

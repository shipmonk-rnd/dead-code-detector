<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Enum;

use PhpParser\Modifiers;

interface Visibility
{

    public const PUBLIC = Modifiers::PUBLIC;
    public const PROTECTED = Modifiers::PROTECTED;
    public const PRIVATE = Modifiers::PRIVATE;

}

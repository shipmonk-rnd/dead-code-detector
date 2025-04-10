<?php declare(strict_types = 1);

namespace ReflectionEnums;

use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Rule\RuleTestCase;

enum MyEnum1: string
{
    case One = 'one';
    case Two = 'two';
}

enum MyEnum2: int
{
    case One = 1;
    case Two = 2; // error: Unused ReflectionEnums\MyEnum2::Two
}


function test() {

    $reflection1 = new \ReflectionEnum(MyEnum1::class);
    $reflection1->getCases();

    $reflection2 = new \ReflectionEnum(MyEnum2::class);
    $reflection2->getCase('One');

}

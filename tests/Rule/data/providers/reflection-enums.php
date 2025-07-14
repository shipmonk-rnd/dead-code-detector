<?php declare(strict_types = 1);

namespace ReflectionEnums;

use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Rule\RuleTestCase;

enum MyEnum0: string
{
    case One = 'one';
    case Two = 'two';

    const Three = 'three';
}

enum MyEnum1: string
{
    case One = 'one';
    case Two = 'two';

    const Three = 'three'; // error: Unused ReflectionEnums\MyEnum1::Three
}

enum MyEnum2: int
{
    case One = 1;
    case Two = 2; // error: Unused ReflectionEnums\MyEnum2::Two
    case Three = 3;
    case Four = 4;

    const Five = 5; // error: Unused ReflectionEnums\MyEnum2::Five
}


function test() {

    $reflection0 = new \ReflectionEnum(MyEnum0::class);
    $reflection0->getConstants();

    $reflection1 = new \ReflectionEnum(MyEnum1::class);
    $reflection1->getCases();

    $reflection2 = new \ReflectionEnum(MyEnum2::class);
    $reflection2->getCase('One');
    $reflection2->getConstant('Three');
    $reflection2->getReflectionConstant('Four');
}

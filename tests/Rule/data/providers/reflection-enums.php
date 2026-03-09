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

enum MyEnum3: string
{
    case Used = 'used';
    case NotUsed = 'not_used'; // error: Unused ReflectionEnums\MyEnum3::NotUsed
}

enum MyEnum4: int
{
    case Used = 1;
    case NotUsed = 2; // error: Unused ReflectionEnums\MyEnum4::NotUsed
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

    new \ReflectionEnumUnitCase(MyEnum3::class, 'Used');
    new \ReflectionEnumBackedCase(MyEnum4::class, 'Used');
}

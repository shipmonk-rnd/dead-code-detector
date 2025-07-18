<?php declare(strict_types = 1);

namespace EnumProvider;

use Nette\Application\UI\Form as UIForm;
use Nette\Application\UI\Presenter;
use Nette\SmartObject;

enum MyEnum0: int {
    case One = 1;
    case Two = 2; // error: Unused EnumProvider\MyEnum0::Two
}

enum MyEnum1: string {
    case Used = 'used';
    case Unused = 'unused'; // error: Unused EnumProvider\MyEnum1::Unused
}

enum MyEnum2: string {
    case Used = 'used';
    case UsedToo = 'used_too';
}

enum MyEnum3: string {
    case Used = 'used';
    case UsedToo = 'used_too';
}

enum MyEnum4: string {
    case One = 'one';
    case Two = 'two';
}

enum MyEnum5: string {
    case One = 'one';
    case Two = 'two'; // error: Unused EnumProvider\MyEnum5::Two
}

enum MyEnum6: string {
    case One = 'one';
    case Two = 'two';
}

enum MyEnum7: string {
    case One = 'one';
    case Two = 'two'; // error: Unused EnumProvider\MyEnum7::Two
    case Three = 'three';
}

/**
 * @param class-string<MyEnum7> $myEnum7Class
 */
function test(
    string $any,
    MyEnum4 $myEnum4,
    MyEnum5 $myEnum5,
    MyEnum6 $myEnum6,
    MyEnum7 $myEnum7,
    string $myEnum7Class
): void
{
    MyEnum0::tryFrom(1);
    MyEnum1::tryFrom('used');
    MyEnum2::from($any);
    MyEnum3::cases();

    $myEnum4->cases();
    $myEnum5->tryFrom('one');

    $myEnum6::cases();
    $myEnum7::tryFrom('one');
    $myEnum7Class::tryFrom('three');
}

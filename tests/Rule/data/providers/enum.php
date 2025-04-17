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

function test(string $any) {
    MyEnum0::tryFrom(1);
    MyEnum1::tryFrom('used');
    MyEnum2::from($any);
    MyEnum3::cases();
}

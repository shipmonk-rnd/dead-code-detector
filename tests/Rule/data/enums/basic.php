<?php declare(strict_types = 1);

namespace DeadEnumBasic;

enum MyEnum {
    case Used;
    case Unused; // error: Unused DeadEnumBasic\MyEnum::Unused
}

function test() {
    echo MyEnum::Used->name;
}

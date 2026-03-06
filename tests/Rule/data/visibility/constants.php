<?php declare(strict_types = 1);

namespace VisibilityConstants;

class MyClass {
    public const PUBLIC_ONLY_SELF = 1; // error: Constant VisibilityConstants\MyClass::PUBLIC_ONLY_SELF has useless public visibility (can be private)
    public const PUBLIC_EXTERNAL = 2; // no error
    protected const PROTECTED_ONLY_SELF = 3; // error: Constant VisibilityConstants\MyClass::PROTECTED_ONLY_SELF has useless protected visibility (can be private)

    public function entry(): void {
        echo self::PUBLIC_ONLY_SELF;
        echo self::PROTECTED_ONLY_SELF;
    }
}

function test(): void {
    $obj = new MyClass();
    $obj->entry();
    echo MyClass::PUBLIC_EXTERNAL;
}

<?php declare(strict_types = 1);

namespace DeadPropertyOverridden1;

class ParentClass {

    public static string $one = 'parent';
    public static string $two = 'parent';

    public static function test(): void
    {
        echo self::$one;
        echo static::$two;
    }
}

class ChildClass extends ParentClass {
    public static string $one = 'child'; // error: Unused DeadPropertyOverridden1\ChildClass::one
    public static string $two = 'child';
}

function test() {
    ParentClass::test();
}

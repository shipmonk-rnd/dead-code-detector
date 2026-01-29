<?php declare(strict_types = 1);

namespace DeadPropertyOverridden1;

class ParentClass {

    public static string $one = 'parent'; // error: Property DeadPropertyOverridden1\ParentClass::$one is never written
    public static string $two = 'parent'; // error: Property DeadPropertyOverridden1\ParentClass::$two is never written

    public static function test(): void
    {
        echo self::$one;
        echo static::$two;
    }
}

class ChildClass extends ParentClass {
    public static string $one = 'child'; // error: Property DeadPropertyOverridden1\ChildClass::$one is never read // error: Property DeadPropertyOverridden1\ChildClass::$one is never written
    public static string $two = 'child'; // error: Property DeadPropertyOverridden1\ChildClass::$two is never written
}

function test() {
    ParentClass::test();
}

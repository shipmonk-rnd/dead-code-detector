<?php declare(strict_types = 1);

namespace DeadPropertyOverridden2;

class ParentClass {

    public static string $one = 'parent'; // error: Property DeadPropertyOverridden2\ParentClass::$one is never written
    public static string $two = 'parent'; // error: Property DeadPropertyOverridden2\ParentClass::$two is never written

    /**
     * @param class-string<ParentClass> $class
     */
    public static function test(string $class): void
    {
        echo $class::$one;
        echo $class::$two;
    }
}

class ChildClass extends ParentClass {
    public static string $one = 'child'; // error: Property DeadPropertyOverridden2\ChildClass::$one is never written
    public static string $two = 'child'; // error: Property DeadPropertyOverridden2\ChildClass::$two is never written
}

function test() {
    ParentClass::test();
}

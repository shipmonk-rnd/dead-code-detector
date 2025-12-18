<?php declare(strict_types = 1);

namespace DeadPropertyOverridden2;

class ParentClass {

    public static string $one = 'parent';
    public static string $two = 'parent';

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
    public static string $one = 'child';
    public static string $two = 'child';
}

function test() {
    ParentClass::test();
}

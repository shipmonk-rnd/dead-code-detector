<?php declare(strict_types = 1);

namespace DeadPropertyStatic;

class TestClass {

    public static string $usedStaticProperty;
    public static string $unusedStaticProperty; // error: Unused DeadPropertyStatic\TestClass::unusedStaticProperty

    public static function initialize(): void
    {
        self::$usedStaticProperty = 'static';
        self::$unusedStaticProperty = 'assigned but unused';
    }

    public static function useStaticProperty(): void
    {
        echo self::$usedStaticProperty;
    }
}


function test() {
    TestClass::initialize();
    TestClass::useStaticProperty();
}

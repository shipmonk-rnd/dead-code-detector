<?php declare(strict_types = 1);

namespace DeadPropertyDynamic;

class TestClass {

    public string $foo;
    public string $bar;
    public string $bag; // error: Unused DeadPropertyDynamic\TestClass::bag
}

/**
 * @param TestClass $class
 * @param 'foo'|'bar' $propertyName
 */
function test(TestClass $class, string $propertyName) {

    echo $class->$propertyName;
}

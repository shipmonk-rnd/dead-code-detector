<?php declare(strict_types = 1);

namespace DeadPropertyDynamic;

class TestClass {

    public string $foo; // error: Property DeadPropertyDynamic\TestClass::$foo is never written
    public string $bar; // error: Property DeadPropertyDynamic\TestClass::$bar is never written
    public string $bag; // error: Property DeadPropertyDynamic\TestClass::$bag is never read // error: Property DeadPropertyDynamic\TestClass::$bag is never written
}

/**
 * @param TestClass $class
 * @param 'foo'|'bar' $propertyName
 */
function test(TestClass $class, string $propertyName) {

    echo $class->$propertyName;
}

<?php declare(strict_types = 1);

namespace DeadPropertyNullsafe;

class TestClass {
    public string $name = 'test'; // error: Property DeadPropertyNullsafe\TestClass::$name is never written
    public string $dead = 'dead'; // error: Property DeadPropertyNullsafe\TestClass::$dead is never read // error: Property DeadPropertyNullsafe\TestClass::$dead is never written
}

function test(?TestClass $test) {
    echo $test?->name;
}

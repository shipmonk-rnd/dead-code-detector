<?php declare(strict_types = 1);

namespace DeadPropertyNullsafe;

class TestClass {
    public string $name = 'test';
    public string $dead = 'dead'; // error: Unused DeadPropertyNullsafe\TestClass::dead
}

function test(?TestClass $test) {
    echo $test?->name;
}

<?php declare(strict_types = 1);

namespace CtorMissing;

class ParentClass
{
}

class Child1 extends ParentClass
{
    public function __construct()
    {
    }
}

/**
 * @param class-string<ParentClass> $class
 */
function test(string $class) {
    new $class();
}

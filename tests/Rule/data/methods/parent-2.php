<?php declare(strict_types = 1);

namespace DeadParent2;

class FooParent
{
    public function method() {}
}

class Foo extends FooParent
{
}

function test(Foo $child)
{
    $child->method();
}

<?php declare(strict_types = 1);

namespace DeadIndirect2;

interface FooInterface
{
    public function method(): void;
}

class FooParent
{
    public function method(): void
    {

    }
}

class Foo extends FooParent implements FooInterface
{

}

function test(FooInterface $iface) {
    $iface->method();
}

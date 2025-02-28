<?php declare(strict_types = 1);

namespace DeadIndirect5;

interface FooInterface
{
    public function method(): void;
}

abstract class FooAbstractParent
{
    abstract public function method(): void; // error: Unused DeadIndirect5\FooAbstractParent::method
}

abstract class FooParent extends FooAbstractParent
{
    public function method(): void {}
}

class Foo extends FooParent implements FooInterface
{

}

function test(FooInterface $iface) {
    $iface->method();
}

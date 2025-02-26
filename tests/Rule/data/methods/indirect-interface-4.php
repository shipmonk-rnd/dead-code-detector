<?php declare(strict_types = 1);

namespace DeadIndirect4;

interface FooInterface
{
    public function method(): void;
}

trait FooTrait
{
    public function method(): void {}
}

class FooParent
{
    use FooTrait;
}

class Foo extends FooParent implements FooInterface
{

}

function test(FooInterface $iface) {
    $iface->method();
}

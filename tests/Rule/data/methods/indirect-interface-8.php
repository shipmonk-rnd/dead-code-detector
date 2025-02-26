<?php declare(strict_types = 1);

namespace DeadIndirect8;

interface FooInterface
{
    public function method(): void;
}

trait FooTraitParent
{
    abstract public function method(): void;
}

trait FooTrait
{
    use FooTraitParent;

    public function method(): void {}
}

abstract class FooParent
{
    public function method(): void {} // error: Unused DeadIndirect8\FooParent::method
}

class Foo extends FooParent implements FooInterface
{
    use FooTrait;
}

function test(FooInterface $iface) {
    $iface->method();
}

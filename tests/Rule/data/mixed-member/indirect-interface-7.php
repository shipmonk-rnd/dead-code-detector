<?php declare(strict_types = 1);

namespace MixedMemberIndirect7;

interface FooInterface
{
    public function method(): void;
}

trait FooTrait
{
    abstract public function method(): void; // not a definer
}

abstract class FooParent
{
    public function method(): void {}
}

class Foo extends FooParent implements FooInterface
{
    use FooTrait;
}

function test(FooInterface $iface, string $method) {
    $iface->$method();
}

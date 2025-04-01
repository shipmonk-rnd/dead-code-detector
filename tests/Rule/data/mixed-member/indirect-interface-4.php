<?php declare(strict_types = 1);

namespace MixedMemberIndirect4;

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

function test(FooInterface $iface, string $method) {
    $iface->$method();
}

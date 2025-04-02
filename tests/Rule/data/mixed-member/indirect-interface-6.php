<?php declare(strict_types = 1);

namespace MixedMemberIndirect6;

interface FooInterface
{
    public function method(): void;
}

interface FooParentInterface
{
    public function method(): void; // error: Unused MixedMemberIndirect6\FooParentInterface::method
}

abstract class FooParent implements FooParentInterface
{
    public function method(): void {}
}

class Foo extends FooParent implements FooInterface
{

}

function test(FooInterface $iface, string $method) {
    $iface->$method();
}

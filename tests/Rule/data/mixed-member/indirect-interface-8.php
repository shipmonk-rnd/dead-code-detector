<?php declare(strict_types = 1);

namespace MixedMemberIndirect8;

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
    public function method(): void {} // error: Unused MixedMemberIndirect8\FooParent::method
}

class Foo extends FooParent implements FooInterface
{
    use FooTrait;
}

function test(FooInterface $iface, string $method) {
    $iface->$method();
}

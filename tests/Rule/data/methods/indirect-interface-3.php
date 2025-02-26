<?php declare(strict_types = 1);

namespace DeadIndirect3;

interface FooInterface
{
    public function foo(): void; // error: Unused DeadIndirect3\FooInterface::foo
}

class FooParent
{
    public function __construct()
    {
        $this->foo();
    }

    public function foo(): void {}
}

class Foo extends FooParent implements FooInterface
{
    public function foo(): void {}
}

new Foo();

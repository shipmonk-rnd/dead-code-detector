<?php declare(strict_types = 1);

namespace DeadIndirect;

interface FooInterface
{
    public function foo(): void;
}

abstract class FooAbstract
{

    public function foo(): void
    {
        $this->foo();
    }
}

class Foo extends FooAbstract implements FooInterface
{

}

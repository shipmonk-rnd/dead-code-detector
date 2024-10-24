<?php declare(strict_types = 1);

namespace DeadIndirect;

interface FooInterface
{
    public function foo(): void; // error: Unused DeadIndirect\FooInterface::foo
}

abstract class FooAbstract
{
    public function __construct()
    {
        $this->foo();
    }

    public function foo(): void
    {

    }
}

class Foo extends FooAbstract implements FooInterface
{

}

new Foo();

<?php declare(strict_types = 1);

namespace DeadIndirect;

interface FooInterface
{
    public function foo(): void; // error: Unused DeadIndirect\FooInterface::foo
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

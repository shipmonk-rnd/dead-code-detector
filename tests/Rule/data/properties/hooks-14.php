<?php declare(strict_types=1);

namespace PropertyHooks14;

class Inner
{

    public string $name {
        get {
            $this->touch();
            return 'inner';
        }
    }

    public function touch(): void
    {
    }

}

class Outer
{

    public function __construct(
        private Inner $inner,
    )
    {
    }

    public string $name {
        get {
            return $this->inner->name; // same property name as Inner::$name, but invokes its get hook
        }
    }

}

function test(): void
{
    $outer = new Outer(new Inner());
    echo $outer->name;
}

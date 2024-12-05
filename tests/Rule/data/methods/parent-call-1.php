<?php declare(strict_types = 1);

namespace ParentCall1;

abstract class AbstractClass
{
    public function foo(): void
    {

    }
}

class Child1 extends AbstractClass
{
    public function foo(): void {
        parent::foo();
    }
}

class Child2 extends AbstractClass
{
    public function foo(): void {} // error: Unused ParentCall1\Child2::foo
}

function testIt(Child1 $child1): void
{
    $child1->foo();
}

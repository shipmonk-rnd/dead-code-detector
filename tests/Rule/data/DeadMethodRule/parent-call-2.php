<?php declare(strict_types = 1);

namespace ParentCall2;

abstract class AbstractClass
{
    public function foo(): void {}
}

class Child1 extends AbstractClass
{
    public function foo(): void {
        // not calling parent
    }
}

function testIt(Child1 $child1): void
{
    $child1->foo();
}

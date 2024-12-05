<?php declare(strict_types = 1);

namespace ParentCall2;

abstract class AbstractClass
{
    public function foo(): void {} // error: Unused ParentCall2\AbstractClass::foo
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

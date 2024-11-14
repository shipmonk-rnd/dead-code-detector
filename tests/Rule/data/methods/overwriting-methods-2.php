<?php declare(strict_types = 1);

namespace DeadOver2;

interface Interface1
{
    public function foo(): void; // error: Unused DeadOver2\Interface1::foo
}

interface Interface2
{
    public function foo(): void; // error: Unused DeadOver2\Interface2::foo
}

abstract class AbstractClass implements Interface1, Interface2
{
    public abstract function foo(): void; // error: Unused DeadOver2\AbstractClass::foo
}

class Child1 extends AbstractClass
{
    public function foo(): void {}
}

class Child2 extends AbstractClass
{
    public function foo(): void {} // error: Unused DeadOver2\Child2::foo
}

function testIt(Child1 $child1): void
{
    $child1->foo(); // makes Child1::foo, AbstractClass::foo, Interface1::foo, Interface2::foo used
}

<?php declare(strict_types = 1);

namespace DeadOver2;

interface Interface1
{
    public function foo(): void;
}

interface Interface2
{
    public function foo(): void;
}

abstract class AbstractClass implements Interface1, Interface2
{
    public abstract function foo(): void;
}

class Child1 extends AbstractClass
{
    public function foo(): void {}
}

class Child2 extends AbstractClass
{
    public function foo(): void {}
}

function testIt(Child1 $child1): void
{
    $child1->foo(); // makes Child1::foo, AbstractClass::foo, Interface1::foo, Interface2::foo used
}

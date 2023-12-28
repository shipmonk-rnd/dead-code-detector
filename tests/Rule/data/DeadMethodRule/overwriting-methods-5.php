<?php declare(strict_types = 1);

namespace DeadOver5;

interface Interface1
{
    public function foo(): void; // error: Unused DeadOver5\Interface1::foo
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

function testIt(Interface2 $interface2): void
{
    $interface2->foo(); // makes Child1::foo, Child2::foo, AbstractClass::foo, Interface2::foo used
}

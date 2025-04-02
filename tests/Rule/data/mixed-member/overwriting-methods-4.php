<?php declare(strict_types = 1);

namespace MixedMemberOver4;

interface Interface1
{
    public function foo(): void;
}

interface Interface2
{
    public function foo(): void; // error: Unused MixedMemberOver4\Interface2::foo
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

function testIt(Interface1 $interface1, string $method): void
{
    $interface1->$method(); // makes Child1::foo, Child2::foo, AbstractClass::foo, Interface1::foo used
}

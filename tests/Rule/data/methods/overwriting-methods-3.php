<?php declare(strict_types = 1);

namespace DeadOver3;

interface Interface1
{
    public function foo(): void; // error: Unused DeadOver3\Interface1::foo
}

interface Interface2
{
    public function foo(): void; // error: Unused DeadOver3\Interface2::foo
}

abstract class AbstractClass implements Interface1, Interface2
{
    public abstract function foo(): void; // error: Unused DeadOver3\AbstractClass::foo
}

class Child1 extends AbstractClass
{
    public function foo(): void {} // error: Unused DeadOver3\Child1::foo
}

class Child2 extends AbstractClass
{
    public function foo(): void {}
}

function testIt(Child2 $child2): void
{
    $child2->foo(); // makes Child2::foo, AbstractClass::foo, Interface1::foo, Interface2::foo used
}
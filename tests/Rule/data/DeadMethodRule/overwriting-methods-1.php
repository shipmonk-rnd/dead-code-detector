<?php declare(strict_types = 1);

namespace DeadOver1;

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

function testIt(AbstractClass $abstractClass): void
{
    $abstractClass->foo(); // makes Child1::foo, Child2::foo, AbstractClass::foo used
}

<?php declare(strict_types = 1);

namespace MixedMemberOver1;

interface Interface1
{
    public function foo(): void; // error: Unused MixedMemberOver1\Interface1::foo
}

interface Interface2
{
    public function foo(): void; // error: Unused MixedMemberOver1\Interface2::foo
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

function testIt(AbstractClass $abstractClass, string $method): void
{
    $abstractClass->$method(); // makes Child1::foo, Child2::foo, AbstractClass::foo used
}

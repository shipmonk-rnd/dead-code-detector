<?php declare(strict_types = 1);

namespace Removal;

interface Interface1
{
    public function foo(): void; // error: Unused Removal\Interface1::foo
}

interface Interface2
{
    public function foo(): void; // error: Unused Removal\Interface2::foo
}

abstract class AbstractClass implements Interface1, Interface2
{
    public abstract function foo(): void; // error: Unused Removal\AbstractClass::foo
}

class Child1 extends AbstractClass
{
    public function foo(): void {} // error: Unused Removal\Child1::foo
}

class Child2 extends AbstractClass
{
    public function foo(): void {}
}

function testIt(Child2 $child2): void
{
    $child2->foo();
}

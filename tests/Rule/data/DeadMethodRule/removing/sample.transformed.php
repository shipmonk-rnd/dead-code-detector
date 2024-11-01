<?php declare(strict_types = 1);

namespace Removal;

interface Interface1
{
}

interface Interface2
{
}

abstract class AbstractClass implements Interface1, Interface2
{
}

class Child1 extends AbstractClass
{
}

class Child2 extends AbstractClass
{
    public function foo(): void {}
}

function testIt(Child2 $child2): void
{
    $child2->foo();
}

<?php declare(strict_types = 1);

namespace Removal;

interface Interface1
{
    const DEAD_IFACE_CONST1 = 1;
    const DEAD_IFACE_CONST2 = 2, DEAD_IFACE_CONST3 = 3;

    public function foo(): void;
}

interface Interface2
{
    public function foo(): void;
}

abstract class AbstractClass implements Interface1, Interface2
{
    public abstract function foo(): void
    ;
}

class Child1 extends AbstractClass
{
    public function foo(): void
    {
    }
}

class Child2 extends AbstractClass
{
    const USED_CONST = 1, DEAD_CONST = 2;

    public function foo(): void
    {
        echo self::USED_CONST;
    }

    public function mixedExcludedUsage(): void {

    }
}

function testIt(Child2 $child2): void
{
    $child2->foo();
    $child2->mixedExcludedUsage();
}

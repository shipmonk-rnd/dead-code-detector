<?php declare(strict_types = 1);

namespace ReflectionNoGenerics;

interface MyParent
{
    const CONST1 = 1;

    public function bar();
}

class Holder2
{
    const CONST1 = 1;
    const CONST2 = 2; // error: Unused ReflectionNoGenerics\Holder2::CONST2

    public function foo() {} // error: Unused ReflectionNoGenerics\Holder2::foo
}

class Holder3
{
    const CONST1 = 1;
    const CONST2 = 2; // error: Unused ReflectionNoGenerics\Holder3::CONST2

    public function bar() {}
}

function testNoGenericTypeKnown(\ReflectionClass $reflection) {
    echo $reflection->getConstant('CONST1'); // marks all constants named CONST1 as used
    echo $reflection->getMethod('bar'); // marks all methods named bar as used
    echo $reflection->getMethods(); // not emitted + we ignore mixed over mixed calls
}

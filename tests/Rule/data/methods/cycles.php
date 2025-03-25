<?php declare(strict_types = 1);

namespace Cycles;


class Foo {
    public function recursion1(): void
    {
        $this->recursion1();
    }

    public function recursion2(): void // error: Unused Cycles\Foo::recursion2
    {
        $this->recursion2();
    }
}

class A {
    public function a(B $b): void // error: Unused Cycles\A::a
    {
        $b->b();
    }
}

class B {
    public function b(A $a): void // error: Unused Cycles\B::b
    {
        $a->a();
    }
}

function test() {
    (new Foo())->recursion1();
}

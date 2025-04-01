<?php declare(strict_types = 1);

namespace MixedMemberTrait15;

trait A {
    public function method1() {}
    public function method2() {}
    public function method3() {}
}

class TraitAliases {
    use A {
        A::method1 as aliased1;
        A::method2 as public;
        A::method3 as aliased3;
    }
}

function test(string $method)
{
    $o = new TraitAliases();
    $o->$method();
}

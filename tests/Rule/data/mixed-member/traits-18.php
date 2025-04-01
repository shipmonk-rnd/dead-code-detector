<?php declare(strict_types = 1);

namespace MixedMemberTrait18;

trait A {
    public function method1() {}
    public function method2() {}
}

trait B {
    public function method3() {}
    public function method4() {}
}

class User {
    use A, B {
        method1 as alias1;
        method3 as alias3;
    }
}

function test(string $method)
{
    $o = new User();
    $o->$method();
}

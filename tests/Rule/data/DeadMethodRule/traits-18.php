<?php declare(strict_types = 1);

namespace DeadTrait18;

trait A {
    // method1 is not dead, this is better-reflection bug: https://github.com/Roave/BetterReflection/pull/1453
    public function method1() {} // error: Unused DeadTrait18\A::method1
    public function method2() {} // error: Unused DeadTrait18\A::method2
}

trait B {
    public function method3() {}
    public function method4() {} // error: Unused DeadTrait18\B::method4
}

class User {
    use A, B {
        method1 as alias1;
        method3 as alias3;
    }
}

$o = new User();
$o->alias1();
$o->alias3();

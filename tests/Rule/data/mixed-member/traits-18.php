<?php declare(strict_types = 1);

namespace MixedMemberTrait18;

trait A {
    // alias1 is not detected a method of User due to better-reflection bug: https://github.com/Roave/BetterReflection/pull/1453
    // this method is not reported as dead just because we support "unknown" method calls
    public function method1() {}
    public function method2() {} // error: Unused MixedMemberTrait18\A::method2
}

trait B {
    public function method3() {}
    public function method4() {} // error: Unused MixedMemberTrait18\B::method4
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

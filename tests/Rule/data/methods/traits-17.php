<?php declare(strict_types = 1);

namespace DeadTrait17;

trait A {
    public function collision1() {} // error: Unused DeadTrait17\A::collision1
    public function collision2() {}
}

trait B {
    public function collision1() {}
    public function collision2() {} // error: Unused DeadTrait17\B::collision2
}

class AliasedTalker {
    use A, B {
        B::collision1 insteadof A;
        A::collision2 insteadof B;
    }
}

$o = new AliasedTalker();
$o->collision1();
$o->collision2();

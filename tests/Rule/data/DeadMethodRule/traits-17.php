<?php declare(strict_types = 1);

namespace DeadTrait17;

trait A {
    public function collission1() {} // error: Unused DeadTrait17\A::collission1
    public function collission2() {}
}

trait B {
    public function collission1() {}
    public function collission2() {} // error: Unused DeadTrait17\B::collission2
}

class AliasedTalker {
    use A, B {
        B::collission1 insteadof A;
        A::collission2 insteadof B;
    }
}

$o = new AliasedTalker();
$o->collission1();
$o->collission2();

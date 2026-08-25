<?php declare(strict_types = 1);

namespace DeadTrait27;

trait A {
    public function talk() { // error: Unused DeadTrait27\A::talk
        echo 'A';
    }
}

trait B {
    public function talk() {
        echo 'B';
    }
}

class CaseMismatchedInsteadof {
    use A, B {
        B::TALK insteadof A; // PHP resolves the method name case-insensitively
    }
}

$o = new CaseMismatchedInsteadof();
$o->talk();

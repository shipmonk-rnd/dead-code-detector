<?php declare(strict_types = 1);

namespace DeadTrait8;

trait A {
    public function smallTalk() {
        echo 'a';
    }
    public function bigTalk() {
        echo 'A';
    }
}

trait B {
    public function smallTalk() {
        echo 'b';
    }
    public function bigTalk() {
        echo 'B';
    }
}

class AliasedTalker {
    use A, B {
        B::smallTalk insteadof A;
        A::bigTalk insteadof B;
        B::bigTalk as talk;
    }
}

$o = new AliasedTalker();
$o->talk();
$o->bigTalk();
$o->smallTalk();

<?php declare(strict_types = 1);

namespace MixedMemberTrait8;

trait A {
    public function smallTalk() { // error: Unused MixedMemberTrait8\A::smallTalk
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

function test(string $method)
{
    $o = new AliasedTalker();
    $o->$method();
}

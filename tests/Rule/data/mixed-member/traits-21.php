<?php declare(strict_types = 1);

namespace MixedMemberTrait21;

trait A {
    public function test(): void { // error: Unused MixedMemberTrait21\A::test
        echo "A";
    }
}


trait B {
    use A;
    public function test(): void {
        echo "B";
    }
}

class Tester {
    use B;
}

function test(string $method)
{
    $o = new Tester();
    $o->$method();
}

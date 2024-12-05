<?php declare(strict_types = 1);

namespace DeadTrait21;

trait A {
    public function test(): void { // error: Unused DeadTrait21\A::test
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

(new Tester())->test();

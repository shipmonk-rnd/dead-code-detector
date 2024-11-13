<?php declare(strict_types = 1);

namespace DeadTraitConst21;

trait A {
    const TEST = 1; // error: Unused DeadTraitConst21\A::TEST
}


trait B {
    use A;
    const TEST = 1;
}

class Tester {
    use B;
}

echo Tester::TEST;

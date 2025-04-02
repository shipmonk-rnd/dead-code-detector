<?php declare(strict_types = 1);

namespace MixedMemberTraitConst21;

trait A {
    const TEST = 1; // error: Unused MixedMemberTraitConst21\A::TEST
}


trait B {
    use A;
    const TEST = 1;
}

class Tester {
    use B;
}

function test(string $const)
{
    echo Tester::{$const};
}

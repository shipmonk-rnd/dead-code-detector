<?php declare(strict_types = 1);

namespace MixedMemberTraitProp21;

trait A {
    public static int $test = 1; // error: Property MixedMemberTraitProp21\A::test is never read
}


trait B {
    use A;
    public static int $test = 1;
}

class Tester {
    use B;
}

function test(string $property)
{
    echo Tester::$$property;
}

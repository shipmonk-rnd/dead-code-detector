<?php declare(strict_types = 1);

namespace MixedMemberTraitProp10;

trait StaticExample {
    public static int $do = 1; // error: Property MixedMemberTraitProp10\StaticExample::$do is never written
}

class Example {
    use StaticExample;
}

function test(string $property)
{
    echo Example::$$property;
}

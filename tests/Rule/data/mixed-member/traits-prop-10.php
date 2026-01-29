<?php declare(strict_types = 1);

namespace MixedMemberTraitProp10;

trait StaticExample {
    public static int $do = 1;
}

class Example {
    use StaticExample;
}

function test(string $property)
{
    echo Example::$$property;
}

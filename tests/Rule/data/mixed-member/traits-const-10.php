<?php declare(strict_types = 1);

namespace MixedMemberTraitConst10;

trait StaticExample {
    const DO = 1;
}

class Example {
    use StaticExample;
}

function test(string $const)
{
    echo Example::{$const};
}

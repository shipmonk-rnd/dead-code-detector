<?php declare(strict_types = 1);

namespace MixedMemberTraitConst1;

trait Trait1 {

    const USED = 1;
    const UNUSED = 2;
}

class User1
{
    use Trait1;
}

class User2
{
    use Trait1;
}

function test(string $const) {
    User2::{$const};
}

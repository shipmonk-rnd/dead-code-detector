<?php declare(strict_types = 1);

namespace MixedMemberTraitConst7;

trait HelloWorld {
    const HELLO = 1; // error: Unused MixedMemberTraitConst7\HelloWorld::HELLO
}

class TheWorldIsNotEnough {
    use HelloWorld;

    const HELLO = 1;
}

function test(string $const)
{
    $o = new TheWorldIsNotEnough();
    echo $o::{$const};
}

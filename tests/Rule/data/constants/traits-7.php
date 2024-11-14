<?php declare(strict_types = 1);

namespace DeadTraitConst7;

trait HelloWorld {
    const HELLO = 1; // error: Unused DeadTraitConst7\HelloWorld::HELLO
}

class TheWorldIsNotEnough {
    use HelloWorld;

    const HELLO = 1;
}

$o = new TheWorldIsNotEnough();
echo $o::HELLO;

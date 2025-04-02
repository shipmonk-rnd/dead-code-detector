<?php

namespace FullMixedConst;

class Foo
{
    const BAR = 1; // error: Unused FullMixedConst\Foo::BAR
}


function test(object $any, string $const) {
    $any::{$const}; // this would mark all constants as used, so we rather ignore it and print warning in -vvv
}

<?php declare(strict_types = 1);

namespace DeadConstExpr;


class P {
    const CONSTANT1 = 1;
    const CONSTANT2 = 1;
}

class C extends P {
    const CONSTANT1 = 2;
    const CONSTANT2 = 2; // error: Unused DeadConstExpr\C::CONSTANT2
}

function test(P $p) {
    echo $p::CONSTANT1; // can be call over C
    echo P::CONSTANT2;
}

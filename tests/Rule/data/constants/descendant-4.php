<?php declare(strict_types = 1);

namespace DeadConstDescendant4;


class P {

    const CONSTANT = 1;

    public function test() {
        echo static::CONSTANT;
    }
}

class C extends P {
    const CONSTANT = 2;
}

$c = new C();
$c->test(); // prints 2

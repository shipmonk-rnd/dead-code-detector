<?php declare(strict_types = 1);

namespace DeadConstDescendant3;


class P {

    const CONSTANT = 1;

    public function test() {
        echo self::CONSTANT;
    }
}

class C extends P {
    const CONSTANT = 2; // error: Unused DeadConstDescendant3\C::CONSTANT
}

$c = new C();
$c->test(); // prints 1

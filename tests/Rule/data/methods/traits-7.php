<?php declare(strict_types = 1);

namespace DeadTrait7;

trait HelloWorld {
    public function sayHello() { // error: Unused DeadTrait7\HelloWorld::sayHello
        echo 'Hello World!';
    }
}

class TheWorldIsNotEnough {
    use HelloWorld;
    public function sayHello() {
        echo 'Hello Universe!';
    }
}

$o = new TheWorldIsNotEnough();
$o->sayHello();

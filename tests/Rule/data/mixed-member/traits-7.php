<?php declare(strict_types = 1);

namespace MixedMemberTrait7;

trait HelloWorld {
    public function sayHello() { // error: Unused MixedMemberTrait7\HelloWorld::sayHello
        echo 'Hello World!';
    }
}

class TheWorldIsNotEnough {
    use HelloWorld;
    public function sayHello() {
        echo 'Hello Universe!';
    }
}

function test(string $method)
{
    $o = new TheWorldIsNotEnough();
    $o->$method();
}

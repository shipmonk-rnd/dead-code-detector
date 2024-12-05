<?php declare(strict_types = 1);

namespace DeadTraitConst6;

class Base {
    const HELLO = 'Hi';
}

trait SayWorld {
    public function sayHello() {
        echo parent::HELLO;
        echo 'World!';
    }
}

class MyHelloWorld extends Base {
    use SayWorld;
}

$o = new MyHelloWorld();
$o->sayHello();

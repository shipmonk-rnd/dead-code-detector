<?php declare(strict_types = 1);

namespace MixedMemberTrait6;

class Base {
    public function sayHello() {
        echo 'Hello ';
    }
}

trait SayWorld {
    public function sayHello() {
        parent::sayHello();
        echo 'World!';
    }
}

class MyHelloWorld extends Base {
    use SayWorld;
}

function test(string $method)
{
    $o = new MyHelloWorld();
    $o->$method();
}

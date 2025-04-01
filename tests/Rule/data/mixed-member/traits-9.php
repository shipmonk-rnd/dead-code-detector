<?php declare(strict_types = 1);

namespace MixedMemberTrait9;

trait Hello {
    public function sayHello() {
        echo 'Hello ';
    }
}

trait World {
    public function sayWorld() {
        echo 'World!';
    }
}

trait HelloWorld {
    use Hello, World;
}

class MyHelloWorld {
    use HelloWorld;
}

function test(string $method) {
    $o = new MyHelloWorld();
    $o->$method();
}

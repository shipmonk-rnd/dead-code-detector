<?php declare(strict_types = 1);

namespace DeadTraitConst9;

trait Hello {
    const HELLO = 1;
}

trait World {
    const WORLD = 2;
}

trait HelloWorld {
    use Hello, World;
}

class MyHelloWorld {
    use HelloWorld;
}

$o = new MyHelloWorld();
echo $o::HELLO;
echo $o::WORLD;

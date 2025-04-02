<?php declare(strict_types = 1);

namespace MixedMemberTraitConst9;

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

function test(string $const)
{
    $o = new MyHelloWorld();
    echo $o::{$const};
}

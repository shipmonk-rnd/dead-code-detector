<?php declare(strict_types = 1);

namespace MixedMemberTraitProp9;

trait Hello {
    public static int $hello = 1;
}

trait World {
    public static int $world = 2;
}

trait HelloWorld {
    use Hello, World;
}

class MyHelloWorld {
    use HelloWorld;
}

function test(string $property)
{
    $o = new MyHelloWorld();
    echo $o::$$property;
}

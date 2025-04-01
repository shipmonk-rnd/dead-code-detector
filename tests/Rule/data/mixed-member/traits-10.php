<?php declare(strict_types = 1);

namespace MixedMemberTrait10;

trait StaticExample {
    public static function doSomething() {
        return 'Doing something';
    }
}

class Example {
    use StaticExample;
}

function test(string $method)
{
    Example::$method();
}

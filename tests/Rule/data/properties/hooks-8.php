<?php declare(strict_types=1);

namespace PropertyHooks8;

class Example
{

    public string $foo {
        get {
            return $this->foo = 'value'; // self-referencing set hook is not called
        }
        set(string $value) {
            self::unused();
        }
    }

    public static function unused() {} // error: Unused PropertyHooks8\Example::unused
}

function test(Example $example) {
    echo $example->foo;
}



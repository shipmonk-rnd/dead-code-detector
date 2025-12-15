<?php declare(strict_types=1);

namespace PropertyHooks9;

class Example
{

    public string $foo {
        get {
            $this->bar = 'value';
            return $this->foo;
        }

    }

    public string $bar { // error: Unused PropertyHooks9\Example::bar
        set(string $value) {
            self::used();
        }
    }

    public static function used() {}
}

function test(Example $example) {
    echo $example->foo;
}



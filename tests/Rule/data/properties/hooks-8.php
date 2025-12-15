<?php declare(strict_types=1);

namespace PropertyHooks8;

class Example
{

    public string $foo {
        get {
            return $this->foo = 'value';
        }
        set(string $value) {
            self::used();
        }
    }

    public static function used() {}
}

function test(Example $example) {
    echo $example->foo;
}



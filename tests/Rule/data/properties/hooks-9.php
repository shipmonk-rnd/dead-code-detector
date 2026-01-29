<?php declare(strict_types=1);

namespace PropertyHooks9;

class Example
{

    public string $foo { // error: Property PropertyHooks9\Example::$foo is never written
        get {
            $this->bar = 'value';
            return $this->foo;
        }

    }

    public string $bar { // error: Property PropertyHooks9\Example::$bar is never read
        set(string $value) {
            self::used();
        }
    }

    public static function used() {}
}

function test(Example $example) {
    echo $example->foo;
}



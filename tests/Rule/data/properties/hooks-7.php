<?php declare(strict_types=1);

namespace PropertyHooks7;

class Example
{
    private int $readCounter = 0; // error: Unused PropertyHooks7\Example::readCounter
    private int $writeCounter = 0; // error: Unused PropertyHooks7\Example::writeCounter

    public string $foo = 'default value' { // error: Unused PropertyHooks7\Example::foo
        get {
            $this->readCounter += 1;
            $this->foo = $this->foo . ' ' . $this->readCounter;
            return $this->foo;
        }
        set(string $value) {
            $this->foo = $value;
            $this->writeCounter += 1;
            self::used();
        }
    }

    public static function used() {

    }
}

function test(Example $example) {
    $example->foo = 'new value';
}



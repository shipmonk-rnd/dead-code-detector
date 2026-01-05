<?php declare(strict_types=1);

namespace PropertyHooks6;

class Example
{
    private int $readCounter = 0;
    private int $writeCounter = 0; // error: Property PropertyHooks6\Example::$writeCounter is never read

    public string $foo = 'default value' {
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

    public static function used() { // error: Unused PropertyHooks6\Example::used

    }
}

function test(Example $example) {
    echo $example->foo;
}



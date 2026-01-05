<?php declare(strict_types=1);

namespace PropertyHooks7;

class Example
{
    private int $readCounter = 0; // error: Property PropertyHooks7\Example::$readCounter is never read
    private int $writeCounter = 0; // error: Property PropertyHooks7\Example::$writeCounter is never read

    public string $foo = 'default value' { // error: Property PropertyHooks7\Example::$foo is never read
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



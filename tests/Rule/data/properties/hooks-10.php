<?php declare(strict_types=1);

namespace PropertyHooks10;

class Example1
{
    public string $foo = 'UPPERCASE' {
        set => strtolower($value) . $this->test();
    }

    public function test(): string { // error: Unused PropertyHooks10\Example1::test
        return 'test';
    }
}

class Example2
{
    public function __construct(
        public string $foo = 'UPPERCASE' {
            set => strtolower($value) . $this->test();
        }
    ) {
    }

    public function test(): string {
        return 'test';
    }
}

// https://x.com/janedbal/status/2001250601381036048
function test() {
    echo new Example1()->foo;
    echo new Example2()->foo;
}



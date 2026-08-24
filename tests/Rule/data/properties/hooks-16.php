<?php declare(strict_types=1);

namespace PropertyHooks16;

// same as hooks-15, but with reversed read order in test()

class Example
{

    public string $prop = '' {
        get {
            $this->prop = 'init';
            return $this->prop;
        }
        set(string $value) {
            $this->log();
            $this->prop = $value;
        }
    }

    public string $other {
        get {
            $this->prop = 'from-other';
            return 'x';
        }
    }

    public function log(): void
    {
    }

}

function test(Example $example): void
{
    echo $example->other;
    echo $example->prop;
}

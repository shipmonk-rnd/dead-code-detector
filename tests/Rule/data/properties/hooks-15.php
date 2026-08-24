<?php declare(strict_types=1);

namespace PropertyHooks15;

class Example
{

    public string $prop = '' {
        get {
            $this->prop = 'init'; // backing store write, does not invoke set hook
            return $this->prop;
        }
        set(string $value) {
            $this->log();
            $this->prop = $value;
        }
    }

    public string $other {
        get {
            $this->prop = 'from-other'; // hooked write, invokes set hook of $prop
            return 'x';
        }
    }

    public function log(): void
    {
    }

}

function test(Example $example): void
{
    echo $example->prop; // visits $prop members via non-propagating backing accesses first
    echo $example->other;
}

<?php declare(strict_types=1);

namespace PropertyHooks17;

class WriteViaClosure
{

    public string $prop = '' {
        get {
            $fn = function (): void {
                $this->prop = 'x'; // closure is a separate compilation unit, this invokes the set hook
            };
            $fn();
            return $this->prop;
        }
        set(string $value) {
            $this->log();
            $this->prop = $value;
        }
    }

    public function log(): void
    {
    }

}

class WriteViaArrowFunction
{

    public string $prop = '' {
        get {
            $fn = fn (): string => $this->prop = 'x'; // arrow function invokes the set hook
            $fn();
            return $this->prop;
        }
        set(string $value) {
            $this->log();
            $this->prop = $value;
        }
    }

    public function log(): void
    {
    }

}

function test(WriteViaClosure $a, WriteViaArrowFunction $b): void
{
    echo $a->prop;
    echo $b->prop;
}

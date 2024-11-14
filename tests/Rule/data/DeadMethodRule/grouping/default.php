<?php declare(strict_types = 1);

namespace Grouping;

class Example
{
    const USED_CONST = 1;
    const UNUSED_CONST = 2;
    const TRANSITIVELY_UNUSED_CONST = 3;

    public function __construct(
    )
    {
        $this->baz();
    }

    public function baz(): void // the only used
    {
        echo self::USED_CONST;
    }


    public function foo(): void // group1 entrypoint
    {
        $this->baz();
        $this->bar();
    }

    public function boo(): void // group2 entrypoint
    {
        $this->bag();
    }

    public function bar(): void
    {
        $this->bag();
    }

    public function bag(): void
    {
        $this->bar(); // loop back
        echo self::TRANSITIVELY_UNUSED_CONST;
    }



    public function recur(): void // self-cycle group
    {
        $this->recur();
    }



    public function recur1(): void // cycle group
    {
        $this->recur2();
    }

    public function recur2(): void
    {
        $this->recur1();
    }

}

new Example();

<?php declare(strict_types = 1);

namespace Grouping;

class Example
{

    public function __construct(
    )
    {
        $this->baz();
    }

    public function baz(): void // the only used
    {

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

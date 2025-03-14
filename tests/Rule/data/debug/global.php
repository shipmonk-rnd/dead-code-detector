<?php

namespace DebugGlobal;

class Foo
{
    public function __construct() {}

    public function chain1()
    {
        $this->chain2();
    }

    public function chain2()
    {

    }
}

(new Foo())->chain1();

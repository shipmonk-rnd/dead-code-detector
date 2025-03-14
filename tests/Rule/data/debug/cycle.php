<?php

namespace DebugCycle;

class Foo
{
    public function __construct() // error: Unused DebugCycle\Foo::__construct
    {
        new Bar();
    }
}

class Bar
{
    public function __construct() // error: Unused DebugCycle\Bar::__construct
    {
        new Foo();
    }
}

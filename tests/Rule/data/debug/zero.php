<?php

namespace DebugZero;

class Foo
{
    public function __construct() // error: Unused DebugZero\Foo::__construct
    {
    }
}

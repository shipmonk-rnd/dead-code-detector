<?php

namespace DebugCtor;

class Foo
{
    private function __construct()
    {
        new self();
    }

}


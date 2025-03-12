<?php

namespace DebugGlobal;

class Foo
{
    public function __construct() {}

    public function chain() {}
}

(new Foo())->chain();

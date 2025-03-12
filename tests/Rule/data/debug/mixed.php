<?php

namespace DebugMixed;

class Foo
{
    public function any()
    {
    }
}

function test(object $any) {
    $any->any();
}

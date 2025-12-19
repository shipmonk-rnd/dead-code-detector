<?php

namespace DebugProperty;

class Foo
{
    public string $prop = 'value';
}

function test(Foo $foo): void
{
    $foo->prop = 'new value';
    echo $foo->prop;
}

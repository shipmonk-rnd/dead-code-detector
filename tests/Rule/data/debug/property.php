<?php

namespace DebugProperty;

class Foo
{
    public string $prop = 'value';
}

function test(Foo $foo): void
{
    echo $foo->prop;
}

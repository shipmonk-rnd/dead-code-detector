<?php

namespace FullMixedProperty;

class Foo
{
    public string $anyProperty; // error: Property FullMixedProperty\Foo::anyProperty is never read
}


function test(object $any, string $property) {
    echo $any->$property; // this would mark whole codebase as used, so we rather ignore it and print warning in -vvv
}

<?php

namespace FullMixedMethod;

class Foo
{
    public function any() {} // error: Unused FullMixedMethod\Foo::any
}


function test(object $any, string $method) {
    $any->$method(); // this would mark whole codebase as used, so we rather ignore it and print warning in -vvv
}

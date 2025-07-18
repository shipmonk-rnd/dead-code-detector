<?php

namespace DebugMixedMember;

class Foo
{
    public function method() {}
}

function test(Foo $foo, string $unknown): void {
    $foo->$unknown();
}

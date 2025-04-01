<?php declare(strict_types = 1);

namespace MixedMemberTrait16;

trait A {
    public function method() {}
}

class TraitAliases {
    use A {
        method as aliased;
    }
}

function test(string $method)
{
    $o = new TraitAliases();
    $o->$method();
}

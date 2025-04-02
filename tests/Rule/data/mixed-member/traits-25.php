<?php declare(strict_types = 1);

namespace MixedMemberTrait25;

trait A {
    public function method() {} // error: Unused MixedMemberTrait25\A::method
}

class TraitAliases {
    use A {
        A::method as aliased;
    }

    public function aliased() {}
    public function method() {}
}

function test(string $method) {
    $o = new TraitAliases();
    $o->$method();
}

<?php declare(strict_types = 1);

namespace DeadTrait24;

trait A {
    public function method() {} // error: Unused DeadTrait24\A::method
}

class TraitAliases {
    use A {
        A::method as aliased;
    }

    public function aliased() {}
}

$o = new TraitAliases();
$o->aliased();

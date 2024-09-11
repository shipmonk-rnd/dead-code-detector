<?php declare(strict_types = 1);

namespace DeadTrait16;

trait A {
    public function method() {}
}

class TraitAliases {
    use A {
        method as aliased;
    }
}

$o = new TraitAliases();
$o->aliased();

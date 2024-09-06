<?php declare(strict_types = 1);

namespace DeadTrait16;

trait A {
    public function method() {} // error: Unused DeadTrait16\A::method
}

class TraitAliases {
    use A {
        method as aliased; // trait name missing in old name, not supported yet
    }
}

$o = new TraitAliases();
$o->aliased();

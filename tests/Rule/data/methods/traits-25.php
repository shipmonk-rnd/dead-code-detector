<?php declare(strict_types = 1);

namespace DeadTrait25;

trait A {
    public function method() {} // error: Unused DeadTrait25\A::method
}

class TraitAliases {
    use A {
        A::method as aliased;
    }

    public function aliased() {}
    public function method() {}
}

$o = new TraitAliases();
$o->aliased();
$o->method();

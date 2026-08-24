<?php declare(strict_types = 1);

namespace DeadTrait26;

trait A {
    public function talk() {
        echo 'A';
    }
}

class CaseMismatchedAlias {
    use A {
        TALK as speak; // PHP resolves the source method name case-insensitively
    }
}

$o = new CaseMismatchedAlias();
$o->speak();

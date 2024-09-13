<?php declare(strict_types = 1);

namespace Nullsafe;

class A {

    public function first(): self {
        return $this;
    }

    public function second(): self {
        return $this;
    }

    public static function secondStatic(): self { // error: Unused Nullsafe\A::secondStatic
        return new self();
    }
}

class B {

    public function test(?A $a): void
    {
        $a?->first()->second();
        $a?->first()::secondStatic();
    }
}

(new B())->test(null);

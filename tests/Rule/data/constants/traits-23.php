<?php declare(strict_types = 1);

namespace DeadTraitConst23;

trait MyTrait {
    public function traitMethod(): void {
        echo Statical::USED_FROM_TRAIT;
    }
}

class Statical {
    const USED_FROM_TRAIT = 1;
}

class Tester {
    use MyTrait;

    public function test(): void {
        $this->traitMethod();
    }
}

(new Tester())->test();

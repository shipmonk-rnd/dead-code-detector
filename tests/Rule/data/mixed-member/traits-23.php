<?php declare(strict_types = 1);

namespace MixedMemberTrait23;

trait MyTrait {
    public function traitMethod(): void {
        Statical::usedFromTrait();
    }
}

class Statical {
    public static function usedFromTrait(): void {}
}

class Tester {
    use MyTrait;

    public function test(): void {
        $this->traitMethod();
    }
}

function test(string $method)
{
    $o = new Tester();
    $o->$method();
}

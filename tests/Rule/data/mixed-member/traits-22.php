<?php declare(strict_types = 1);

namespace MixedMemberTrait22;

trait MyTrait {
    public function test(): void {
        echo "MyTrait";
    }
}

class Tester {
    use MyTrait {
        test as aliased;
    }

    public function test(): void {
        echo "Tester";
    }
}

function test(string $method)
{
    $o = new Tester();
    $o->$method();
}

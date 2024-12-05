<?php declare(strict_types = 1);

namespace DeadTrait22;

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

(new Tester())->test();
(new Tester())->aliased();

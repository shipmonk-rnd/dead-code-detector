<?php declare(strict_types = 1);

namespace DeadTrait19;

trait Trait1 {
    abstract public function method1(): void;
}

class User1 {
    use Trait1;

    public function method1(): void {}
}

(new User1())->method1();

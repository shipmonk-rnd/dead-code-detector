<?php declare(strict_types = 1);

namespace DeadTrait20;

trait MyTrait {
    abstract public function method(): void;
}

abstract class Intermediate {
    use MyTrait;

    abstract public function method(): void; // error: Unused DeadTrait20\Intermediate::method
}

class User extends Intermediate {

    public function method(): void {}
}


(new User())->method();

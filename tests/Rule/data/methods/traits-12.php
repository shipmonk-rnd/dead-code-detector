<?php declare(strict_types = 1);

namespace DeadTrait12;

trait SomeTrait {
    abstract public function method(): void;
}

class Origin {

    public function method(): void {}
}

class User extends Origin {
    use SomeTrait;
}

(new User())->method();


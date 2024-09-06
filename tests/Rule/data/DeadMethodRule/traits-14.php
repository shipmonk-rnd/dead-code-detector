<?php declare(strict_types = 1);

namespace DeadTrait14;

trait SomeTrait {
    public function method(): void {}
}

class ParentClass {
    public function method(): void {} // error: Unused DeadTrait14\ParentClass::method
}

class User extends ParentClass {
    use SomeTrait;
}

(new User())->method();


<?php declare(strict_types = 1);

namespace DeadTrait11;

class Origin {

    protected function method(): string {
        return 'Doing something';
    }
}

class User extends Origin {
    use SomeTrait;

    public function __construct()
    {
        $this->method();
    }
}

new User();


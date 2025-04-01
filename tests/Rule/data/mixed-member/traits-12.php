<?php declare(strict_types = 1);

namespace MixedMemberTrait12;

trait SomeTrait {
    abstract public function method(): void;
}

class Origin {

    public function method(): void {}
}

class User extends Origin {
    use SomeTrait;
}

function test(User $user, string $method) {
    $user->$method();
}


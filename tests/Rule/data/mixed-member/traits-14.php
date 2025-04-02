<?php declare(strict_types = 1);

namespace MixedMemberTrait14;

trait SomeTrait {
    public function method(): void {}
}

class ParentClass {
    public function method(): void {} // error: Unused MixedMemberTrait14\ParentClass::method
}

class User extends ParentClass {
    use SomeTrait;
}

function test(User $user, string $method) {
    $user->$method();
}


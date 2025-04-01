<?php declare(strict_types = 1);

namespace MixedMemberTrait20;

trait MyTrait {
    abstract public function method(): void;
}

abstract class Intermediate {
    use MyTrait;

    abstract public function method(): void; // error: Unused MixedMemberTrait20\Intermediate::method
}

class User extends Intermediate {

    public function method(): void {}
}


(new User())->method();

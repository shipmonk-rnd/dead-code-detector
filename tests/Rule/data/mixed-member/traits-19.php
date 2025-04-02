<?php declare(strict_types = 1);

namespace MixedMemberTrait19;

trait Trait1 {
    abstract public function method1(): void;
}

class User1 {
    use Trait1;

    public function method1(): void {}
}

function test(string $method)
{
    $o = new User1();
    $o->$method();
}

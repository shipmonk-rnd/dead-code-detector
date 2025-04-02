<?php declare(strict_types = 1);

namespace MixedMemberTrait1;

trait Trait1 {

    public static function used(): void {}
}

class User1
{
    use Trait1;
}

class User2
{
    use Trait1;
}

function test(User2 $user2, string $method) {
    User2::$method();
}

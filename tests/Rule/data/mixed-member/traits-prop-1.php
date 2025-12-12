<?php declare(strict_types = 1);

namespace MixedMemberTraitProp1;

trait Trait1 {

    public int $used = 1;
    public int $unused = 2;
}

class User1
{
    use Trait1;
}

class User2
{
    use Trait1;
}

function test(User2 $user, string $property) {
    $user->$property;
}

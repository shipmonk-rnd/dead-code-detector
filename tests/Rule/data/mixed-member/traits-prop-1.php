<?php declare(strict_types = 1);

namespace MixedMemberTraitProp1;

trait Trait1 {

    public int $used = 1; // error: Property MixedMemberTraitProp1\Trait1::$used is never written
    public int $unused = 2; // error: Property MixedMemberTraitProp1\Trait1::$unused is never written
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

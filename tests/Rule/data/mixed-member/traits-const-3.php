<?php declare(strict_types = 1);

namespace MixedMemberTraitConst3;

trait MyTrait1 {

    const USED = 1;
}

interface TraitInterface
{
    const USED = 1; // error: Unused MixedMemberTraitConst3\TraitInterface::USED
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(MyUser1 $user1, string $const): void {
    echo $user1::{$const};
}

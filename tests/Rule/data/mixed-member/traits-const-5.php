<?php declare(strict_types = 1);

namespace MixedMemberTraitConst5;

trait MyTrait1 {

    // because all children override this const, it is unused
    const USED = 1; // error: Unused MixedMemberTraitConst5\MyTrait1::USED
}

interface TraitInterface
{
    const USED = 1;
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;

    const USED = 1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;

    const USED = 1;
}

function testIface(TraitInterface $interface, string $const): void {
    echo $interface::{$const};
}

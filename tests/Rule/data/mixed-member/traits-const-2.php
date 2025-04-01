<?php declare(strict_types = 1);

namespace MixedMemberTraitConst2;

trait MyTrait1 {

    const USED = 1;
}

interface TraitInterface
{
    const USED = 1;
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(TraitInterface $interface, string $const): void {
    echo $interface::{$const};
}

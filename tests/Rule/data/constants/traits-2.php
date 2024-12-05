<?php declare(strict_types = 1);

namespace DeadTraitConst2;

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

function testIface(TraitInterface $interface): void {
    echo $interface::USED;
}

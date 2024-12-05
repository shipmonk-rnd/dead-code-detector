<?php declare(strict_types = 1);

namespace DeadTraitConst3;

trait MyTrait1 {

    const USED = 1;
    const UNUSED = 2; // error: Unused DeadTraitConst3\MyTrait1::UNUSED
}

interface TraitInterface
{
    const USED = 1; // error: Unused DeadTraitConst3\TraitInterface::USED
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(MyUser1 $user1): void {
    echo $user1::USED;
}

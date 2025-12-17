<?php declare(strict_types = 1);

namespace MixedMemberTraitProp3;

trait MyTrait1 {

    public int $used = 1;
}

interface TraitInterface
{
    public int $used { get; } // error: Property MixedMemberTraitProp3\TraitInterface::used is never read
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(MyUser1 $user1, string $property): void {
    echo $user1->$property;
}

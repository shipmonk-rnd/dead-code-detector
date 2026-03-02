<?php declare(strict_types = 1);

namespace MixedMemberTraitProp2;

trait MyTrait1 {

    public int $used = 1;
}

interface TraitInterface
{
    public int $used { get; }
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(TraitInterface $interface, string $property): void {
    echo $interface->$property;
}

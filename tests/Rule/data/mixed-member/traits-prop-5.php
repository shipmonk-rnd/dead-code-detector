<?php declare(strict_types = 1);

namespace MixedMemberTraitProp5;

trait MyTrait1 {

    // because all children override this property, it is unused
    public int $used = 1; // error: Property MixedMemberTraitProp5\MyTrait1::used is never read
}

interface TraitInterface
{
    public int $used { get; }
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;

    public int $used = 1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;

    public int $used = 1;
}

function testIface(TraitInterface $interface, string $property): void {
    echo $interface->$property;
}

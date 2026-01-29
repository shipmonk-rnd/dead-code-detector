<?php declare(strict_types = 1);

namespace MixedMemberTraitProp5;

trait MyTrait1 {

    // because all children override this property, it is unused
    public int $used = 1; // error: Property MixedMemberTraitProp5\MyTrait1::$used is never read // error: Property MixedMemberTraitProp5\MyTrait1::$used is never written
}

interface TraitInterface
{
    public int $used { get; } // error: Property MixedMemberTraitProp5\TraitInterface::$used is never written
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;

    public int $used = 1; // error: Property MixedMemberTraitProp5\MyUser1::$used is never written
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;

    public int $used = 1; // error: Property MixedMemberTraitProp5\MyUser2::$used is never written
}

function testIface(TraitInterface $interface, string $property): void {
    echo $interface->$property;
}

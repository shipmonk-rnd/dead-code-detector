<?php declare(strict_types = 1);

namespace DeadTrait5;

trait MyTrait1 {

    // because all children override this method, it is unused
    public function used(): void {}
}

interface TraitInterface
{
    public function used(): void;
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;

    public function used(): void {}
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;

    public function used(): void {}
}

function testIface(TraitInterface $interface): void {
    $interface->used();
}

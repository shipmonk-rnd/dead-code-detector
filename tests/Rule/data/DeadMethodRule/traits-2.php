<?php declare(strict_types = 1);

namespace DeadTrait2;

trait MyTrait1 {

    public function used(): void {}
}

interface TraitInterface
{
    public function used(): void;
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
    $interface->used();
}

<?php declare(strict_types = 1);

namespace DeadTrait3;

trait MyTrait1 {

    public function used(): void {}
    public function unused(): void {} // error: Unused DeadTrait3\MyTrait1::unused
}

interface TraitInterface
{
    public function used(): void; // error: Unused DeadTrait3\TraitInterface::used
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
    $user1->used();
}

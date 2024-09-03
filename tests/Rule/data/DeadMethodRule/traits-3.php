<?php declare(strict_types = 1);

namespace DeadTrait3;

trait MyTrait1 {

    public function used(): void {}
    public function unused(): void {}
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

function testIface(MyUser1 $user1): void {
    $user1->used();
}

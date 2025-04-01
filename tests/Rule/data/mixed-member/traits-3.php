<?php declare(strict_types = 1);

namespace MixedMemberTrait3;

trait MyTrait1 {

    public function used(): void {}
    public function unused(): void {}
}

interface TraitInterface
{
    public function used(): void; // error: Unused MixedMemberTrait3\TraitInterface::used
}

class MyUser1 implements TraitInterface
{
    use MyTrait1;
}

class MyUser2 implements TraitInterface
{
    use MyTrait1;
}

function testIface(MyUser1 $user1, string $method): void {
    $user1->$method();
}

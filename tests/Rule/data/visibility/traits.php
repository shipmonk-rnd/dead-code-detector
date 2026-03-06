<?php declare(strict_types = 1);

namespace VisibilityTraits;

trait MyTrait {
    public function traitPublicSelfOnly(): void {} // error: Method VisibilityTraits\MyTrait::traitPublicSelfOnly has useless public visibility (can be private)
    public function traitPublicExternal(): void {} // no error - used externally via host class
}

class HostA {
    use MyTrait;

    public function entry(): void { // no error - used externally
        $this->traitPublicSelfOnly();
    }
}

class HostB {
    use MyTrait;

    public function entry(): void { // no error - used externally
        $this->traitPublicSelfOnly();
    }
}

function test(): void {
    $a = new HostA();
    $a->entry();
    $a->traitPublicExternal();

    $b = new HostB();
    $b->entry();
    $b->traitPublicExternal();
}

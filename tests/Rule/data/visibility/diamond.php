<?php declare(strict_types = 1);

namespace VisibilityDiamond;

// Diamond: class implements two interfaces with the same method

interface InterfaceA {
    public function shared(): void;
}

interface InterfaceB {
    public function shared(): void;
}

class Diamond implements InterfaceA, InterfaceB {
    public function shared(): void {} // no error - implements interface members, must be public

    public function ownMethod(): void {} // error: Method VisibilityDiamond\Diamond::ownMethod has useless public visibility (can be private)

    public function entry(): void {
        $this->ownMethod();
    }
}

// Interface extending another interface
interface BaseInterface {
    public function base(): void;
}

interface ExtendedInterface extends BaseInterface {
    public function extended(): void;
}

class Implementor implements ExtendedInterface {
    public function base(): void {} // no error - implements interface
    public function extended(): void {} // no error - implements interface
    public function own(): void {} // error: Method VisibilityDiamond\Implementor::own has useless public visibility (can be private)

    public function entry(): void {
        $this->own();
    }
}

function test(): void {
    $d = new Diamond();
    $d->entry();

    /** @var InterfaceA $a */
    $a = $d;
    $a->shared();

    $i = new Implementor();
    $i->entry();

    /** @var ExtendedInterface $e */
    $e = $i;
    $e->base();
    $e->extended();
}

<?php declare(strict_types = 1);

namespace VisibilityInterface;

interface MyInterface {
    public function interfaceMethod(): void; // no error - interface, always public
    const INTERFACE_CONST = 1; // no error - interface constant
}

class Implementor implements MyInterface {
    public function interfaceMethod(): void {} // no error - implements interface, must be public
    public function ownMethod(): void {} // error: Method VisibilityInterface\Implementor::ownMethod has useless public visibility (can be private)

    public function entry(): void { // no error - used externally
        $this->ownMethod();
    }
}

function test(): void {
    $obj = new Implementor();
    $obj->entry();
    echo MyInterface::INTERFACE_CONST;

    // Call through interface type to make it used
    /** @var MyInterface $iface */
    $iface = $obj;
    $iface->interfaceMethod();
}

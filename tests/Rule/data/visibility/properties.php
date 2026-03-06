<?php declare(strict_types = 1);

namespace VisibilityProperties;

class MyClass {
    public string $publicOnlySelf = ''; // error: Property VisibilityProperties\MyClass::$publicOnlySelf has useless public visibility (can be private)
    public string $publicUsedExternally = ''; // no error
    protected string $protectedOnlySelf = ''; // error: Property VisibilityProperties\MyClass::$protectedOnlySelf has useless protected visibility (can be private)

    public function entry(): void {
        echo $this->publicOnlySelf;
        echo $this->protectedOnlySelf;
    }
}

class PromotedPropertyClass {
    public function __construct(
        public string $publicPromotedOnlySelf = '', // error: Property VisibilityProperties\PromotedPropertyClass::$publicPromotedOnlySelf has useless public visibility (can be private)
        public string $publicPromotedExternal = '',  // no error
    ) {
    }

    public function entry(): void {
        echo $this->publicPromotedOnlySelf;
    }
}

function test(): void {
    $obj = new MyClass();
    echo $obj->publicUsedExternally;
    $obj->entry();

    $obj2 = new PromotedPropertyClass();
    echo $obj2->publicPromotedExternal;
    $obj2->entry();
}

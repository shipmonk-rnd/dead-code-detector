<?php declare(strict_types = 1);

namespace VisibilityMethods;

class SelfOnly {
    public function publicOnlySelf(): void {} // error: Method VisibilityMethods\SelfOnly::publicOnlySelf has useless public visibility (can be private)
    protected function protectedOnlySelf(): void {} // error: Method VisibilityMethods\SelfOnly::protectedOnlySelf has useless protected visibility (can be private)
    private function privateMethod(): void {} // no error - already private

    public function entry(): void { // no error - used externally
        $this->publicOnlySelf();
        $this->protectedOnlySelf();
        $this->privateMethod();
    }
}

class ParentClass {
    public function publicUsedByChild(): void {} // error: Method VisibilityMethods\ParentClass::publicUsedByChild has useless public visibility (can be protected)
    public function publicUsedExternally(): void {} // no error - used externally
    protected function protectedUsedByChild(): void {} // no error - used by child, protected is correct
    protected function protectedOnlySelf(): void {} // error: Method VisibilityMethods\ParentClass::protectedOnlySelf has useless protected visibility (can be private)

    public function entry(): void { // no error - used externally
        $this->protectedOnlySelf();
    }
}

class ChildClass extends ParentClass {
    public function childEntry(): void { // no error - used externally
        $this->publicUsedByChild();
        $this->protectedUsedByChild();
    }
}

function test(): void {
    $obj = new SelfOnly();
    $obj->entry();

    $parent = new ParentClass();
    $parent->publicUsedExternally();
    $parent->entry();

    $child = new ChildClass();
    $child->childEntry();
}

<?php declare(strict_types = 1);

namespace VisibilityInheritance;

class Parent_ {
    protected function parentProtected(): void {} // no error - overridden by child, protected is needed (child calls via parent::)

    public function publicOnlyHierarchy(): void {} // error: Method VisibilityInheritance\Parent_::publicOnlyHierarchy has useless public visibility (can be protected)
}

class Child extends Parent_ {
    protected function parentProtected(): void { // no error - overrides parent, floor is protected
        parent::parentProtected();
    }

    public function entry(): void { // no error - used externally
        $this->parentProtected();
        $this->publicOnlyHierarchy();
    }
}

function test(): void {
    $child = new Child();
    $child->entry();
}

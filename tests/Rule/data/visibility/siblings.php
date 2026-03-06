<?php declare(strict_types = 1);

namespace VisibilitySiblings;

// Two children of the same parent with different usage patterns

class Parent_ {
    public function method(): void {} // no error - used externally (by one child externally)
}

class ChildA extends Parent_ {
    public function entryA(): void {
        // calls parent's method from within hierarchy
        $this->method();
    }
}

class ChildB extends Parent_ {
}

// Parent method only used within hierarchy by children

class HierarchyOnly {
    public function onlyChildren(): void {} // error: Method VisibilitySiblings\HierarchyOnly::onlyChildren has useless public visibility (can be protected)
}

class SiblingA extends HierarchyOnly {
    public function entryA(): void {
        $this->onlyChildren();
    }
}

class SiblingB extends HierarchyOnly {
    public function entryB(): void {
        $this->onlyChildren();
    }
}

// Sibling classes where one overrides and the other doesn't

class BaseWithVirtual {
    public function virtual(): void {} // no error - used externally

    public function entry(): void {
        $this->virtual();
    }
}

class SiblingOverrides extends BaseWithVirtual {
    public function virtual(): void {} // no error - used externally
}

class SiblingInherits extends BaseWithVirtual {
    // inherits virtual() without overriding
}

function test(): void {
    $b = new ChildB();
    $b->method(); // external usage of Parent_::method

    $a = new ChildA();
    $a->entryA();

    $sa = new SiblingA();
    $sa->entryA();

    $sb = new SiblingB();
    $sb->entryB();

    $bv = new BaseWithVirtual();
    $bv->entry();

    $so = new SiblingOverrides();
    $so->virtual();

    $si = new SiblingInherits();
    $si->virtual();
}

<?php declare(strict_types = 1);

namespace VisibilityDeepHierarchy;

// Grandparent → Parent → Child → Grandchild chain

class GrandParent_ {
    public function onlyUsedBySelf(): void {} // error: Method VisibilityDeepHierarchy\GrandParent_::onlyUsedBySelf has useless public visibility (can be private)
    public function usedByGrandchild(): void {} // error: Method VisibilityDeepHierarchy\GrandParent_::usedByGrandchild has useless public visibility (can be protected)
    public function usedExternally(): void {} // no error - used externally

    public function entry(): void {
        $this->onlyUsedBySelf();
    }
}

class Parent_ extends GrandParent_ {
}

class Child extends Parent_ {
}

class GrandChild extends Child {
    public function childEntry(): void {
        $this->usedByGrandchild();
    }
}

function test(): void {
    $g = new GrandParent_();
    $g->entry();
    $g->usedExternally();

    $gc = new GrandChild();
    $gc->childEntry();
}

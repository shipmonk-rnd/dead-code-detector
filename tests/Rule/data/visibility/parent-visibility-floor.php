<?php declare(strict_types = 1);

namespace VisibilityParentFloor;

// Child cannot reduce visibility below parent's level

class ParentPublic {
    public function method(): void {} // no error - used externally
}

class ChildOfPublic extends ParentPublic {
    // Overrides parent's public method, used only by self
    // But parent declared it public → floor is public
    public function method(): void {} // no error - parent has it as public, floor prevents reduction
    public function entry(): void {
        $this->method();
    }
}

// Protected parent floor

class ParentProtected {
    protected function method(): void {} // no error - overridden and used properly

    public function parentEntry(): void {
        $this->method();
    }
}

class ChildOfProtected extends ParentProtected {
    // Overrides parent's protected method, used only by self
    // Parent is protected → floor is protected
    protected function method(): void {} // no error - protected matches parent floor
    public function childEntry(): void {
        $this->method();
    }
}

// Multi-level: grandparent sets the floor

class GrandParentWithProtected {
    protected function deep(): void {} // no error

    public function gpEntry(): void {
        $this->deep();
    }
}

class MiddleNoOverride extends GrandParentWithProtected {
}

class GrandChildOverrides extends MiddleNoOverride {
    // grandparent has it protected → floor is protected
    protected function deep(): void {} // no error - floor from grandparent is protected

    public function gcEntry(): void {
        $this->deep();
    }
}

function test(): void {
    $p = new ParentPublic();
    $p->method();

    $c = new ChildOfPublic();
    $c->entry();

    $pp = new ParentProtected();
    $pp->parentEntry();

    $cp = new ChildOfProtected();
    $cp->childEntry();

    $gp = new GrandParentWithProtected();
    $gp->gpEntry();

    $gc = new GrandChildOverrides();
    $gc->gcEntry();
}

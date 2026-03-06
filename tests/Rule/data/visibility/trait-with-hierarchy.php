<?php declare(strict_types = 1);

namespace VisibilityTraitWithHierarchy;

// Trait used by a class in an inheritance hierarchy

trait SharedTrait {
    public function traitMethod(): void {} // error: Method VisibilityTraitWithHierarchy\SharedTrait::traitMethod has useless public visibility (can be protected)
    public function traitSelfOnly(): void {} // error: Method VisibilityTraitWithHierarchy\SharedTrait::traitSelfOnly has useless public visibility (can be private)
}

class Base {
    use SharedTrait;

    public function baseEntry(): void {
        $this->traitSelfOnly();
    }
}

class Derived extends Base {
    public function derivedEntry(): void {
        // Uses trait method from parent context → HIERARCHY origin
        $this->traitMethod();
    }
}

// Trait used alongside interface implementation

interface Doable {
    public function doWork(): void;
}

trait DoableTrait {
    public function doWork(): void {} // no error - host implements interface
}

class Worker implements Doable {
    use DoableTrait;
}

// Trait method overridden in host class → trait method visibility doesn't matter (dead)
trait OverriddenTrait {
    public function overridden(): void {} // no error - overridden in host, zero usages on trait → skipped
}

class OverridingHost {
    use OverriddenTrait;

    public function overridden(): void {} // error: Method VisibilityTraitWithHierarchy\OverridingHost::overridden has useless public visibility (can be private)

    public function entry(): void {
        $this->overridden();
    }
}

function test(): void {
    $b = new Base();
    $b->baseEntry();

    $d = new Derived();
    $d->derivedEntry();

    /** @var Doable $w */
    $w = new Worker();
    $w->doWork();

    $o = new OverridingHost();
    $o->entry();
}

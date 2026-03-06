<?php declare(strict_types = 1);

namespace VisibilityTraitConstsProp;

// Trait providing constants

trait ConstTrait {
    public const TRAIT_CONST_SELF = 1; // error: Constant VisibilityTraitConstsProp\ConstTrait::TRAIT_CONST_SELF has useless public visibility (can be private)
    public const TRAIT_CONST_EXTERNAL = 2; // no error - used externally
}

class ConstHost {
    use ConstTrait;

    public function entry(): void {
        echo self::TRAIT_CONST_SELF;
    }
}

// Trait providing properties

trait PropTrait {
    public string $traitPropSelf = ''; // error: Property VisibilityTraitConstsProp\PropTrait::$traitPropSelf has useless public visibility (can be private)
    public string $traitPropExternal = ''; // no error - used externally
}

class PropHost {
    use PropTrait;

    public function entry(): void {
        echo $this->traitPropSelf;
    }
}

// Trait constant used via hierarchy of host

trait HierarchyConstTrait {
    public const HC = 1; // error: Constant VisibilityTraitConstsProp\HierarchyConstTrait::HC has useless public visibility (can be protected)
}

class HierarchyHost {
    use HierarchyConstTrait;
}

class HierarchyChild extends HierarchyHost {
    public function entry(): void {
        echo parent::HC;
    }
}

function test(): void {
    $c = new ConstHost();
    $c->entry();
    echo ConstHost::TRAIT_CONST_EXTERNAL;

    $p = new PropHost();
    $p->entry();
    echo $p->traitPropExternal;

    $hc = new HierarchyChild();
    $hc->entry();
}

<?php declare(strict_types = 1);

namespace VisibilityFixFinalConst;

class FixFinalConst {
    final public const FINAL_TO_PRIVATE = 1; // error: Constant VisibilityFixFinalConst\FixFinalConst::FINAL_TO_PRIVATE has useless public visibility (can be private)
    final protected const FINAL_PROTECTED_TO_PRIVATE = 2; // error: Constant VisibilityFixFinalConst\FixFinalConst::FINAL_PROTECTED_TO_PRIVATE has useless protected visibility (can be private)

    public function entry(): void { // no error - used externally
        echo self::FINAL_TO_PRIVATE;
        echo self::FINAL_PROTECTED_TO_PRIVATE;
    }
}

class FixFinalConstParent {
    final public const FINAL_TO_PROTECTED = 1; // error: Constant VisibilityFixFinalConst\FixFinalConstParent::FINAL_TO_PROTECTED has useless public visibility (can be protected)

    public function entry(): void { // no error - used externally
    }
}

class FixFinalConstChild extends FixFinalConstParent {
    public function childEntry(): void { // no error - used externally
        echo parent::FINAL_TO_PROTECTED;
    }
}

function test(): void {
    $obj = new FixFinalConst();
    $obj->entry();

    $parent = new FixFinalConstParent();
    $parent->entry();

    $child = new FixFinalConstChild();
    $child->childEntry();
}

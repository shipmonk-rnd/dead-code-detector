<?php declare(strict_types = 1);

namespace VisibilityDescendantOverride;

// Descendant overriding a member forces at least protected visibility

class Base {
    // Only used by self, but overridden by child → needs protected
    public function overriddenMethod(): void {} // error: Method VisibilityDescendantOverride\Base::overriddenMethod has useless public visibility (can be protected)

    // Used only by self, NOT overridden → can be private
    public function notOverridden(): void {} // error: Method VisibilityDescendantOverride\Base::notOverridden has useless public visibility (can be private)

    public const OVERRIDDEN_CONST = 1; // error: Constant VisibilityDescendantOverride\Base::OVERRIDDEN_CONST has useless public visibility (can be protected)
    public const NOT_OVERRIDDEN_CONST = 2; // error: Constant VisibilityDescendantOverride\Base::NOT_OVERRIDDEN_CONST has useless public visibility (can be private)

    public function entry(): void {
        $this->overriddenMethod();
        $this->notOverridden();
        echo self::OVERRIDDEN_CONST;
        echo self::NOT_OVERRIDDEN_CONST;
    }
}

class Child extends Base {
    public function overriddenMethod(): void {} // no error - zero usages, skipped

    public const OVERRIDDEN_CONST = 10; // no error - zero usages, skipped
}

// Multiple levels of override

class TopLevel {
    protected function deep(): void {} // no error - protected is correct: overridden by bottom

    public function entry(): void {
        $this->deep();
    }
}

class MiddleLevel extends TopLevel {
}

class BottomLevel extends MiddleLevel {
    protected function deep(): void {} // no error - zero usages, skipped
}

function test(): void {
    $b = new Base();
    $b->entry();

    $t = new TopLevel();
    $t->entry();
}

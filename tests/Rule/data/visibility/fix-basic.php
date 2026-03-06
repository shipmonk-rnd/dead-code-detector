<?php declare(strict_types = 1);

namespace VisibilityFixBasic;

class FixClass {
    public const PUBLIC_CONST = 1; // error: Constant VisibilityFixBasic\FixClass::PUBLIC_CONST has useless public visibility (can be private)
    protected const PROTECTED_CONST = 2; // error: Constant VisibilityFixBasic\FixClass::PROTECTED_CONST has useless protected visibility (can be private)

    public string $publicProp = ''; // error: Property VisibilityFixBasic\FixClass::$publicProp has useless public visibility (can be private)
    protected string $protectedProp = ''; // error: Property VisibilityFixBasic\FixClass::$protectedProp has useless protected visibility (can be private)

    public function publicMethod(): void {} // error: Method VisibilityFixBasic\FixClass::publicMethod has useless public visibility (can be private)
    protected function protectedMethod(): void {} // error: Method VisibilityFixBasic\FixClass::protectedMethod has useless protected visibility (can be private)

    public function entry(): void { // no error - used externally
        echo self::PUBLIC_CONST;
        echo self::PROTECTED_CONST;
        echo $this->publicProp;
        echo $this->protectedProp;
        $this->publicMethod();
        $this->protectedMethod();
    }
}

class FixParentChild {
    public function parentMethod(): void {} // error: Method VisibilityFixBasic\FixParentChild::parentMethod has useless public visibility (can be protected)

    public function entry(): void { // no error - used externally
    }
}

class FixChild extends FixParentChild {
    public function childEntry(): void { // no error - used externally
        $this->parentMethod();
    }
}

class FixPromoted {
    public function __construct(
        public string $promotedProp = '', // error: Property VisibilityFixBasic\FixPromoted::$promotedProp has useless public visibility (can be private)
    ) {
    }

    public function entry(): void { // no error - used externally
        echo $this->promotedProp;
    }
}

function test(): void {
    $obj = new FixClass();
    $obj->entry();

    $parent = new FixParentChild();
    $parent->entry();

    $child = new FixChild();
    $child->childEntry();

    $promoted = new FixPromoted();
    $promoted->entry();
}

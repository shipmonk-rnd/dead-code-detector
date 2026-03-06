<?php declare(strict_types = 1);

namespace VisibilityEdgeCases;

// Dead methods should NOT get visibility warnings (no usages = skipped)
class WithDeadMethod {
    public function deadMethod(): void {} // no error: member has zero usages, skipped by UselessVisibilityRule
}

// Constructor visibility
class WithConstructor {
    protected function __construct() {} // error: Method VisibilityEdgeCases\WithConstructor::__construct has useless protected visibility (can be private)

    public static function create(): self {
        return new self();
    }
}

// Enum methods
enum MyEnum {
    case A;

    public function enumMethod(): void {} // error: Method VisibilityEdgeCases\MyEnum::enumMethod has useless public visibility (can be private)

    public function entry(): void { // no error - used externally
        $this->enumMethod();
    }
}

function test(): void {
    WithConstructor::create();

    MyEnum::A->entry();
}

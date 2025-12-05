<?php declare(strict_types = 1);

namespace DeadPropertyPromoted;

class TestClass {

    public function __construct(
        public string $usedPromotedProperty,
        public string $unusedPromotedProperty, // error: Unused DeadPropertyPromoted\TestClass::unusedPromotedProperty
        private string $usedPrivatePromotedProperty,
        private string $unusedPrivatePromotedProperty, // error: Unused DeadPropertyPromoted\TestClass::unusedPrivatePromotedProperty
    ) {
        echo $this->usedPrivatePromotedProperty;
    }
}

function test() {
    $obj = new TestClass('used1', 'unused1', 'used2', 'unused2');
    echo $obj->usedPromotedProperty;
}

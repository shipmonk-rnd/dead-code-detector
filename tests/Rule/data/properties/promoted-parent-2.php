<?php declare(strict_types = 1);

namespace DeadPropertyPromotedParent2;

class TestParent {

    public function __construct(
        public string $property
    ) {
    }
}

class TestChild extends TestParent {

    public function __construct(
        string $property
    ) {
        $parent = parent::class;
        $ctor = '__construct';
        $parent::$ctor($property);
    }

}

function test() {
    echo new TestChild('foo')->property;
}

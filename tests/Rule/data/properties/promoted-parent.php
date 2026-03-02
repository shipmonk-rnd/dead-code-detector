<?php declare(strict_types = 1);

namespace DeadPropertyPromotedParent;

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
        parent::__construct($property);
    }

}

function test() {
    echo new TestChild('foo')->property;
}

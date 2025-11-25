<?php declare(strict_types = 1);

namespace DeadClone;

class CloneClass1 {
    public function __clone() {}
}

class CloneClassChild extends CloneClass1 {
    public function __clone() {} // error: Unused DeadClone\CloneClassChild::__clone
}

class CloneClass2 {
    public function __clone() {} // error: Unused DeadClone\CloneClass2::__clone
}

class ParentToClone {
    public function __clone() {}
}

class ChildClass extends ParentToClone {
    public function __clone() {
        parent::__clone();
    }
}

class Coord
{
    public function __construct(
        public int $x,
        public int $y,
    ) {}

    public function __clone() {}
}

clone new CloneClass1();
clone new ChildClass();

clone(new Coord(1, 2), [ // PHP 8.5 clone with
    'y' => 1,
]);

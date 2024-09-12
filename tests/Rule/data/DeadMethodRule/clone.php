<?php declare(strict_types = 1);

namespace DeadClone;

class CloneClass1 {
    public function __clone() {}
}

class ParentToClone {
    public function __clone() {} // error: Unused DeadClone\ParentToClone::__clone
}

class ChildClass extends ParentToClone {
    public function __clone() {}
}

clone new CloneClass1();
clone new ChildClass();

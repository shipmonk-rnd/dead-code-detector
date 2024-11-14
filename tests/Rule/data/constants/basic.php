<?php declare(strict_types = 1);

namespace DeadConstBasic;

interface TestInterface {

    const INTERFACE_CONSTANT = 1;
}

class TestParent implements TestInterface {

    const PARENT_CONSTANT = 1;

    public function parentMethod(): void
    {
        echo self::PARENT_CONSTANT;
        echo self::INTERFACE_CONSTANT;
    }
}

final class TestClass extends TestParent {

    const CONSTANT = 1;
    const CONSTANT_DEAD = 2; // error: Unused DeadConstBasic\TestClass::CONSTANT_DEAD

    public function __construct()
    {
        echo self::CONSTANT;
        $this->parentMethod();
    }

}

new TestClass();

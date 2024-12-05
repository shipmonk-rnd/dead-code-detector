<?php declare(strict_types = 1);

namespace DeadConstOverride;


class TestParent {

    const CONSTANT1 = 1; // error: Unused DeadConstOverride\TestParent::CONSTANT1
    const CONSTANT2 = 2;
}

final class TestClass extends TestParent {

    const CONSTANT1 = 1;
    const CONSTANT2 = 2; // error: Unused DeadConstOverride\TestClass::CONSTANT2

    public function __construct()
    {
        echo self::CONSTANT1;
        echo parent::CONSTANT2;
    }

}

new TestClass();

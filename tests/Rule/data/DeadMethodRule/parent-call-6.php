<?php declare(strict_types = 1);

namespace ParentCall6;

trait TestTrait
{
    public function __construct() {} // error: Unused ParentCall6\TestTrait::__construct
}

abstract class TestParent
{
    use TestTrait;

    public function __construct() {} // error: Unused ParentCall6\TestParent::__construct
}

class TestChild extends TestParent
{
    public function __construct() {}
}


new TestChild();

<?php declare(strict_types = 1);

namespace ParentCall4;

class RootClass
{
    public function __construct()
    {
    }
}

class AbstractClass extends RootClass
{
    public function __construct() // error: Unused ParentCall4\AbstractClass::__construct
    {
    }
}

class Child1 extends AbstractClass
{
    public function __construct()
    {
        RootClass::__construct();
    }
}

new Child1();

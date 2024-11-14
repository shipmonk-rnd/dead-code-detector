<?php declare(strict_types = 1);

namespace ParentCall3;

abstract class AbstractClass
{
    public function __construct()
    {
    }
}

class Child1 extends AbstractClass
{
    public function __construct()
    {
        parent::__construct();
    }
}

new Child1();

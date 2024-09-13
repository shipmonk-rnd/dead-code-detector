<?php declare(strict_types = 1);

namespace Ctor;

class ParentClass
{
    public function __construct()
    {
    }
}

class Child1 extends ParentClass
{
    public function __construct()
    {
    }
}

new ParentClass();

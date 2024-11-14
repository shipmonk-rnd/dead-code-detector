<?php declare(strict_types = 1);

namespace CtorInterface;

interface MyInterface
{
    public function __construct(); // error: Unused CtorInterface\MyInterface::__construct
}

class Child1 implements MyInterface
{
    public function __construct()
    {
    }
}

new Child1();

<?php declare(strict_types = 1);

namespace ParentCall5;


class ParentClass
{
    public function method() {}
}

class ChildClass extends ParentClass
{
    public function method() {} // error: Unused ParentCall5\ChildClass::method
}

(new ParentClass())->method();

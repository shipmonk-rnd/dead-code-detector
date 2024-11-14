<?php declare(strict_types = 1);

namespace ParentCall5;


class ParentClass
{
    public function method() {}
}

class ChildClass extends ParentClass
{
    public function method() {}
}

// this cannot be call over descendant, but there is no such info in PHPStan's ObjectType
// ideally, ChildClass::method() should be marked as dead, but it is currently impossible
(new ParentClass())->method();

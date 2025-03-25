<?php declare(strict_types = 1);

namespace DeadConstDescendant2;


class ParentClass
{
    const CONSTANT = 1;
}

class ChildClass extends ParentClass
{
    const CONSTANT = 1;
}

function test(ParentClass $class) {
    echo $class::CONSTANT;
}


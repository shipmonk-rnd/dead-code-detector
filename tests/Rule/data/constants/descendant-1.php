<?php declare(strict_types = 1);

namespace DeadConstDescendant1;


class ParentClass
{
    const CONSTANT = 1;
}

class ChildClass extends ParentClass
{
    const CONSTANT = 1; // error: Unused DeadConstDescendant1\ChildClass::CONSTANT
}

echo (new ParentClass())::CONSTANT;

// test is similar to tests/Rule/data/methods/parent-call-5.php

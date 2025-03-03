<?php declare(strict_types = 1);

namespace GroupingOrder;

class ClassTwo
{
    public static function two()
    {
        ClassOne::one();
    }

}

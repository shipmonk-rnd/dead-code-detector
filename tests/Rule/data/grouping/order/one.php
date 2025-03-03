<?php declare(strict_types = 1);

namespace GroupingOrder;

class ClassOne
{
    public static function one()
    {
        ClassTwo::two();
    }

}

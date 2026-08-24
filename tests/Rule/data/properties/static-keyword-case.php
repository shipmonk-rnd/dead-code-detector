<?php declare(strict_types = 1);

namespace StaticKeywordCaseProperty;

class Base
{

    public static int $p = 1;

    public function touch(): int
    {
        Static::$p = 5;
        return Static::$p;
    }

}

class Child extends Base
{

    public static int $p = 2;

}

(new Child())->touch();

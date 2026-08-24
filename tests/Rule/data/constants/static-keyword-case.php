<?php declare(strict_types = 1);

namespace StaticKeywordCaseConst;

class Base
{

    public const K = 1;

    public function read(): int
    {
        return STATIC::K;
    }

}

class Child extends Base
{

    public const K = 2;

}

(new Child())->read();

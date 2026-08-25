<?php declare(strict_types = 1);

namespace StaticKeywordCaseMethod;

class Base
{

    public function callIt(): void
    {
        Static::m();
    }

    public static function create(): static
    {
        return new STATIC();
    }

    public static function m(): void
    {
    }

}

class Child extends Base
{

    public function __construct()
    {
    }

    public static function m(): void
    {
    }

}

(new Child())->callIt();
Child::create();

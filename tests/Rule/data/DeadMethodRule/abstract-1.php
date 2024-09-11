<?php declare(strict_types = 1);

namespace Abstract1;

abstract class AbstractClass
{
    abstract protected static function bar(): void; // error: Unused Abstract1\AbstractClass::bar
}

class Implementor extends AbstractClass
{
    public function __construct()
    {
        self::bar();
    }

    protected static function bar(): void
    {
    }
}

new Implementor();

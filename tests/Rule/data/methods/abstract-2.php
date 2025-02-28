<?php declare(strict_types = 1);

namespace Abstract2;

abstract class AbstractClass
{
    abstract public function bar(): void; // error: Unused Abstract2\AbstractClass::bar
}

class Implementor extends AbstractClass
{
    public function bar(): void { }
}

function test(Implementor $implementor): void {
    $implementor->bar();
}

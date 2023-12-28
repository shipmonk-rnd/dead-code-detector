<?php declare(strict_types = 1);

namespace DeadMap;

class ArrayMapTest
{

    public function __construct()
    {
        array_map([$this, 'calledMagically'], ['a']);
    }

    private function notCalledMagically(string $foo): string // error: Unused DeadMap\ArrayMapTest::notCalledMagically
    {
        return $foo;
    }

    private function calledMagically(string $foo): string
    {
        return $foo;
    }
}

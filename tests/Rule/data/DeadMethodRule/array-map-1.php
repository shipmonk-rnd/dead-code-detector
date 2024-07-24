<?php declare(strict_types = 1);

namespace DeadMap;

class ArrayMapTest
{

    public function __construct()
    {
        array_map([$this, 'calledMagically'], ['a']);
        array_filter([], [$this, 'calledMagically2']);
        [$this, 'calledMagically3'];
    }

    private function notCalledMagically(string $foo): string // error: Unused DeadMap\ArrayMapTest::notCalledMagically
    {
        return $foo;
    }

    private function calledMagically(string $foo): string
    {
        return $foo;
    }

    private function calledMagically2(): void {}
    private function calledMagically3(): void {}
}

new ArrayMapTest();

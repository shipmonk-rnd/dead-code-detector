<?php declare(strict_types = 1);

namespace DeadMap;

class ArrayMapTest
{

    public function __construct()
    {
        array_map([$this, 'method2'], ['a']);
        array_filter([], [$this, 'method3']);
        [$this, 'method4'];
        [self::class, 'method5'];
        ['static', 'method6']; // https://github.com/phpstan/phpstan/issues/11594
        [new self(), 'method7'];
    }

    public function method1(string $foo): void {} // error: Unused DeadMap\ArrayMapTest::method1
    public function method2(): void {}
    public function method3(): void {}
    public function method4(): void {}
    public static function method5(): void {}
    public static function method6(): void {} // error: Unused DeadMap\ArrayMapTest::method6
    public function method7(): void {}
}

class Child extends ArrayMapTest {

    public function method2(): void {}
    public function method3(): void {}
    public function method4(): void {}
    public static function method5(): void {} // should be reported (https://github.com/phpstan/phpstan-src/pull/3372)
    public static function method6(): void {} // error: Unused DeadMap\Child::method6
    public function method7(): void {} // error: Unused DeadMap\Child::method7

}

function test() {
    new ArrayMapTest();
}

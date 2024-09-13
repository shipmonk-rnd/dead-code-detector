<?php

namespace DynamicMethod;

class Test {

    public static function a(): void {} // error: Unused DynamicMethod\Test::a
    public static function b(): void {} // error: Unused DynamicMethod\Test::b

    public function c(): void {} // error: Unused DynamicMethod\Test::c
    public function d(): void {} // error: Unused DynamicMethod\Test::d
}

/**
 * @param 'a'|'b' $method
 */
function test(string $method): void {
    Test::$method();
}

/**
 * @param 'c'|'d'|'e' $method
 */
function test2(Test $test, string $method): void {
    $test->$method();
}

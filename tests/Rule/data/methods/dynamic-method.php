<?php

namespace DynamicMethod;

class Test {

    public static function a(): void {}
    public static function b(): void {}

    public function c(): void {}
    public function d(): void {}
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

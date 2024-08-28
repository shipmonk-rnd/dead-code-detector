<?php

namespace ClassStringCall;

class ClassWithMethod {

    public static function someMethod(): void {}
}

/**
 * @param class-string<ClassWithMethod> $class
 */
function test(string $class): void {
    $class::someMethod();
}

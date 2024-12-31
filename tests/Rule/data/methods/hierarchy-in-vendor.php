<?php

namespace HierachyInVendor;

use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Rule\RuleTestCase;

class SomeTest extends RuleTestCase {

    public static function someMethod(): void {}
}

/**
 * @param class-string<TestCase> $class
 */
function test(string $class): void {
    $class::someMethod();
}

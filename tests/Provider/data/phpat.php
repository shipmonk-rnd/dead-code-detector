<?php declare(strict_types = 1);

namespace PhpatTestFixture;

use PHPat\Test\Attributes\TestRule;

class RegisteredArchitectureTest
{

    public function testSrcDoesNotDependOnTests(): void
    {
    }

    #[TestRule]
    public function customNamedRule(): void
    {
    }

    public function helperNotInvokedByPhpat(): void
    {
    }

    private function testPrivate(): void
    {
    }

}

class NotRegisteredArchitectureTest
{

    public function testSomething(): void
    {
    }

}

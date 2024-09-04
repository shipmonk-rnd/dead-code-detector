<?php declare(strict_types = 1);

namespace PhpUnit;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

trait TraitTestCase {
    #[Before] // need to be checked in context of user, not in context of trait
    public function callBefore(): void
    {
    }
}

class SomeTest extends TestCase
{
    use TraitTestCase;

    #[DataProvider('provideFromAttribute')]
    public function testFoo(string $arg): void
    {
    }

    #[DataProvider(methodName: 'provideFromAttributeWithNamedArgument')]
    public function testFoo2(string $arg): void
    {
    }

    /**
     * @dataProvider provideFromPhpDoc
     */
    public function testBar(string $arg): void
    {
    }

    #[Test]
    public function someTestCase(): void
    {
    }

    #[Before]
    public function someBeforeCall(): void
    {
    }

    public static function provideFromAttribute(): array
    {
        return [];
    }

    public static function provideFromAttributeWithNamedArgument(): array
    {
        return [];
    }

    public static function provideFromPhpDoc(): array
    {
        return [];
    }


    /**
     * @afterClass
     */
    public function afterClassAnnotation(): void
    {

    }

    /**
     * @before
     */
    public function beforeAnnotation(): void
    {

    }

}

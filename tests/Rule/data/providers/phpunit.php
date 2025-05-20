<?php declare(strict_types = 1);

namespace PhpUnit;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

trait TraitTestCase {
    #[Before] // need to be checked in context of user, not in context of trait
    public function callBefore(): void
    {
    }
}

class ExternalProvider {
    public static function provideOne(): array
    {
        return [];
    }

    public static function provideTwo(): array
    {
        return [];
    }
}

class SomeTest extends TestCase
{
    use TraitTestCase {
        callBefore as anotherCallBefore;
    }

    #[DataProvider('provideFromAttribute')]
    public function testFoo(string $arg): void
    {
    }

    #[DataProvider(methodName: 'provideFromAttributeWithNamedArgument')]
    public function testFoo2(string $arg): void
    {
    }

    #[DataProviderExternal(ExternalProvider::class, 'provideOne')]
    public function testExternal1(string $arg): void
    {
    }

    #[DataProviderExternal(methodName: 'provideTwo', className: ExternalProvider::class)]
    public function testExternal2(string $arg): void
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

abstract class TestCaseBase extends TestCase
{
    abstract public static function providerTest(): array;

    #[DataProvider('providerTest')]
    public function testFoo(string|null $phpValue, string|null $serialized): void
    {
    }
}

final class SomeExtendingTest extends TestCaseBase
{
    public static function providerTest(): array
    {
        return [];
    }
}


abstract class TestCaseParent extends TestCase
{
    public static function providerInParent(): array
    {
        return [];
    }
}

final class TestProviderInParent extends TestCaseParent
{
    #[DataProvider('providerInParent')]
    public function testFoo(): void
    {
    }
}

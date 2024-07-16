<?php declare(strict_types = 1);

namespace PhpUnit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SomeTest extends TestCase
{

    #[DataProvider('provideFromAttribute')]
    public function testFoo(string $arg): void
    {
    }

    /**
     * @dataProvider provideFromPhpDoc
     */
    public function testBar(string $arg): void
    {
    }

    public static function provideFromAttribute(): array
    {
        return [];
    }

    public static function provideFromPhpDoc(): array
    {
        return [];
    }

}

<?php declare(strict_types = 1);

namespace PhpUnit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SomeTest extends TestCase
{


    #[DataProvider('provide')]
    public function testFoo(string $arg): void
    {
    }

    public static function provide(): array
    {
        return [];
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Output;

use PHPUnit\Framework\TestCase;

final class TextUtilsTest extends TestCase
{

    /**
     * @dataProvider provideData
     */
    public function testPluralize(
        int $count,
        string $singular,
        string $expected
    ): void
    {
        self::assertSame($expected, TextUtils::pluralize($count, $singular));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function provideData(): iterable
    {
        yield 'singular count' => [1, 'error', 'error'];
        yield 'simple plural' => [2, 'error', 'errors'];
        yield 'word ending in s' => [2, 'class', 'classes'];
        yield 'word ending in x' => [2, 'box', 'boxes'];
        yield 'word ending in sh' => [2, 'brush', 'brushes'];
        yield 'word ending in ch' => [2, 'match', 'matches'];
        yield 'word ending in y with consonant' => [2, 'property', 'properties'];
        yield 'word ending in y with vowel' => [2, 'key', 'keys'];
        yield 'zero count' => [0, 'item', 'items'];
        yield 'large count' => [100, 'method', 'methods'];
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PHPUnit\Framework\TestCase;

class SerializationTest extends TestCase
{

    /**
     * @dataProvider provideData
     */
    public function testSerialization(CollectedUsage $expected, string $serialized): void
    {
        $unserialized = CollectedUsage::deserialize($serialized);

        self::assertSame($serialized, $expected->serialize());
        self::assertEquals($expected, $unserialized);
    }

    /**
     * @return iterable<array{CollectedUsage, string}>
     */
    public static function provideData(): iterable
    {
        yield [
            new CollectedUsage(
                new ClassMethodUsage(
                    null,
                    new ClassMethodRef('Some', 'method', false),
                ),
                null,
            ),
            '{"e":null,"t":1,"o":null,"m":{"c":"Some","m":"method","d":false}}',
        ];
        yield [
            new CollectedUsage(
                new ClassConstantUsage(
                    new ClassMethodRef('Clazz', 'method', false),
                    new ClassConstantRef(null, 'CONSTANT', true),
                ),
                'excluder',
            ),
            '{"e":"excluder","t":2,"o":{"c":"Clazz","m":"method","d":false},"m":{"c":null,"m":"CONSTANT","d":true}}',
        ];
    }

}

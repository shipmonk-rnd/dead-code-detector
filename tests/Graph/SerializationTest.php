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
        self::assertSame($serialized, $expected->serialize());
        self::assertEquals($expected, CollectedUsage::deserialize($serialized));
    }

    /**
     * @return iterable<array{CollectedUsage, string}>
     */
    public static function provideData(): iterable
    {
        yield [
            new CollectedUsage(
                new ClassConstantUsage(
                    new UsageOrigin('Clazz', 'method', '/app/index.php', 7, null, null),
                    new ClassConstantRef(null, 'CONSTANT', true),
                ),
                'excluder',
            ),
            '{"e":"excluder","t":2,"o":{"c":"Clazz","m":"method","f":"\/app\/index.php","l":7,"p":null,"n":null},"m":{"c":null,"m":"CONSTANT","d":true}}',
        ];
    }

}

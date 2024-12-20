<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PHPUnit\Framework\TestCase;

class ClassMemberUsageTest extends TestCase
{

    /**
     * @dataProvider provideData
     */
    public function testSerialization(ClassMemberUsage $expected, string $serialized): void
    {
        $unserialized = ClassMemberUsage::deserialize($serialized);

        self::assertSame($serialized, $expected->serialize());
        self::assertEquals($expected, $unserialized);
    }

    /**
     * @return iterable<array{ClassMemberUsage, string}>
     */
    public static function provideData(): iterable
    {
        yield [
            new ClassMethodUsage(
                null,
                new ClassMethodRef('Some', 'method', false),
            ),
            '{"t":1,"o":null,"m":{"c":"Some","m":"method","d":false}}',
        ];
        yield [
            new ClassConstantUsage(
                new ClassMethodRef('Clazz', 'method', false),
                new ClassConstantRef(null, 'CONSTANT', true),
            ),
            '{"t":2,"o":{"c":"Clazz","m":"method","d":false},"m":{"c":null,"m":"CONSTANT","d":true}}',
        ];
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PHPStan\TrinaryLogic;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

final class SerializationTest extends TestCase
{

    /**
     * @dataProvider provideData
     */
    public function testSerialization(
        string $filePath,
        CollectedUsage $expected,
        string $serialized
    ): void
    {
        self::assertSame($serialized, $expected->serialize($filePath));
        self::assertEquals($expected, CollectedUsage::deserialize($serialized, $filePath));
    }

    /**
     * @return iterable<string, array{string, CollectedUsage, string}>
     */
    public static function provideData(): iterable
    {
        yield 'path optimized' => [
            '/app/index.php',
            new CollectedUsage(
                new ClassConstantUsage(
                    new UsageOrigin('Clazz', 'method', MemberType::METHOD, AccessType::READ, '/app/index.php', 7, null, null),
                    new ClassConstantRef(null, 'CONSTANT', true, TrinaryLogic::createNo()),
                ),
                'excluder',
            ),
            '{"e":"excluder","t":2,"a":1,"o":{"c":"Clazz","m":"method","a":1,"t":1,"f":"_","l":7,"p":null,"n":null},"m":{"c":null,"m":"CONSTANT","d":true,"e":-1}}',
        ];

        yield 'path differs' => [
            '/app/different.php',
            new CollectedUsage(
                new ClassConstantUsage(
                    new UsageOrigin('Clazz', 'method', MemberType::METHOD, AccessType::READ, '/app/index.php', 7, null, null),
                    new ClassConstantRef(null, 'CONSTANT', true, TrinaryLogic::createMaybe()),
                ),
                'excluder',
            ),
            '{"e":"excluder","t":2,"a":1,"o":{"c":"Clazz","m":"method","a":1,"t":1,"f":"\/app\/index.php","l":7,"p":null,"n":null},"m":{"c":null,"m":"CONSTANT","d":true,"e":0}}',
        ];
    }

}

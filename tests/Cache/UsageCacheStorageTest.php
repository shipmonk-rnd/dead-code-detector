<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use PHPStan\TrinaryLogic;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function getmypid;
use function sys_get_temp_dir;

final class UsageCacheStorageTest extends TestCase
{

    public function testWriteAndReadRoundTrip(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hashes = $cache->write($usages, $scopeFile);

        self::assertCount(1, $hashes);

        $restored = $cache->read($hashes[0], $scopeFile);

        self::assertCount(2, $restored);
        self::assertEquals($usages[0], $restored[0]);
        self::assertEquals($usages[1], $restored[1]);
    }

    public function testWriteReturnsSameHashForSameData(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hashes1 = $cache->write($usages, $scopeFile);
        $hashes2 = $cache->write($usages, $scopeFile);

        self::assertSame($hashes1, $hashes2);
    }

    public function testReadMissingHashThrows(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-missing', offloadCollectorData: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DCD cache file not found');

        $cache->read('nonexistenthash', '/app/index.php');
    }

    public function testDisabledCachePassesDataThrough(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-disabled', offloadCollectorData: false);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $strings = $cache->write($usages, $scopeFile);

        self::assertCount(2, $strings);

        $restored = [];

        foreach ($strings as $string) {
            foreach ($cache->read($string, $scopeFile) as $usage) {
                $restored[] = $usage;
            }
        }

        self::assertCount(2, $restored);
        self::assertEquals($usages[0], $restored[0]);
        self::assertEquals($usages[1], $restored[1]);
    }

    public function testSerializedUsageContainsNoNewline(): void
    {
        $scopeFile = '/app/index.php';

        foreach ($this->createSampleUsages() as $usage) {
            $serialized = $usage->serialize($scopeFile);
            self::assertStringNotContainsString("\n", $serialized, 'Serialized usage must not contain newlines (used as separator in cache files)');
        }
    }

    public function testGcRemovesUnreadFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dcd-test-gc-' . getmypid();
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hash1 = $cache->write([$usages[0]], $scopeFile);
        $hash2 = $cache->write([$usages[1]], $scopeFile);

        // Only read hash1, so hash2 should be cleaned up
        $cache->read($hash1[0], $scopeFile);
        $cache->gc();

        // hash1 should still be readable by a fresh instance
        $freshCache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $restored = $freshCache->read($hash1[0], $scopeFile);
        self::assertCount(1, $restored);

        // hash2 should be gone
        $this->expectException(LogicException::class);
        $freshCache->read($hash2[0], $scopeFile);
    }

    /**
     * @return array{CollectedUsage, CollectedUsage}
     */
    private function createSampleUsages(): array
    {
        return [
            new CollectedUsage(
                new ClassMethodUsage(
                    new UsageOrigin(className: 'App\Foo', memberName: 'bar', memberType: MemberType::METHOD, accessType: AccessType::READ, fileName: '/app/index.php', line: 10, provider: null, note: null),
                    new ClassMethodRef('App\Baz', 'qux', possibleDescendant: false),
                ),
                null,
            ),
            new CollectedUsage(
                new ClassConstantUsage(
                    new UsageOrigin(className: 'App\Foo', memberName: 'bar', memberType: MemberType::METHOD, accessType: AccessType::READ, fileName: '/app/index.php', line: 15, provider: null, note: null),
                    new ClassConstantRef('App\Config', 'VERSION', possibleDescendant: false, isEnumCase: TrinaryLogic::createNo()),
                ),
                null,
            ),
        ];
    }

}

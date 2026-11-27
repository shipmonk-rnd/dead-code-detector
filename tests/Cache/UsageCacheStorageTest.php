<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use PHPStan\TrinaryLogic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function file_put_contents;
use function getmypid;
use function glob;
use function sys_get_temp_dir;
use function touch;
use function uniqid;

final class UsageCacheStorageTest extends TestCase
{

    private const NO_RESULT_CACHE = '/nonexistent/resultCache.php';

    public function testWriteAndReadRoundTrip(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', self::NO_RESULT_CACHE, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hashes = $cache->pack($usages, $scopeFile);

        self::assertCount(1, $hashes);

        $restored = $cache->unpack($hashes[0], $scopeFile);

        self::assertCount(2, $restored);
        self::assertEquals($usages[0], $restored[0]);
        self::assertEquals($usages[1], $restored[1]);
    }

    public function testWriteReturnsSameHashForSameData(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', self::NO_RESULT_CACHE, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hashes1 = $cache->pack($usages, $scopeFile);
        $hashes2 = $cache->pack($usages, $scopeFile);

        self::assertSame($hashes1, $hashes2);
    }

    public function testReadMissingHashThrows(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-missing', self::NO_RESULT_CACHE, offloadCollectorData: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DCD cache file not found');

        $cache->unpack('nonexistenthash', '/app/index.php');
    }

    public function testDisabledCachePassesDataThrough(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-disabled', self::NO_RESULT_CACHE, offloadCollectorData: false);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $strings = $cache->pack($usages, $scopeFile);

        self::assertCount(2, $strings);

        $restored = [];

        foreach ($strings as $string) {
            foreach ($cache->unpack($string, $scopeFile) as $usage) {
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
        $cache = new UsageCacheStorage($tmpDir, self::NO_RESULT_CACHE, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hash1 = $cache->pack([$usages[0]], $scopeFile);
        $hash2 = $cache->pack([$usages[1]], $scopeFile);

        // Only read hash1, so hash2 should be cleaned up
        $cache->unpack($hash1[0], $scopeFile);
        $cache->gc();

        // hash1 should still be readable by a fresh instance
        $freshCache = new UsageCacheStorage($tmpDir, self::NO_RESULT_CACHE, offloadCollectorData: true);
        $restored = $freshCache->unpack($hash1[0], $scopeFile);
        self::assertCount(1, $restored);

        // hash2 should be gone
        $this->expectException(LogicException::class);
        $freshCache->unpack($hash2[0], $scopeFile);
    }

    #[DataProvider('provideResultCacheModificationTimes')]
    public function testGcRemovesUnreadFilesOnlyWhenThisProcessSavedResultCache(
        int $secondsAfterProcessStart,
        int $expectedFileCount,
    ): void
    {
        $tmpDir = sys_get_temp_dir() . '/' . uniqid('dcd-test-gc-result-cache-', true);
        $resultCachePath = $tmpDir . '/resultCache.php';
        $cache = new UsageCacheStorage($tmpDir, $resultCachePath, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $readHash = $cache->pack([$usages[0]], $scopeFile);
        $cache->pack([$usages[1]], $scopeFile);
        $cache->unpack($readHash[0], $scopeFile);

        $processStartTime = $_SERVER['REQUEST_TIME'] ?? null;
        self::assertIsInt($processStartTime);
        self::assertNotFalse(file_put_contents($resultCachePath, '<?php return [];'));
        self::assertTrue(touch($resultCachePath, $processStartTime + $secondsAfterProcessStart));

        $cache->gc();

        $files = glob($tmpDir . '/dcd/*/*.dat');
        self::assertNotFalse($files);
        self::assertCount($expectedFileCount, $files);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideResultCacheModificationTimes(): iterable
    {
        yield 'saved by an earlier process' => [-1, 2];
        yield 'saved in the start second of this process' => [0, 2];
        yield 'saved by this process' => [1, 1];
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

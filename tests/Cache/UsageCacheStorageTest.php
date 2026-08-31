<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function mkdir;
use function scandir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use const FILE_APPEND;

final class UsageCacheStorageTest extends TestCase
{

    private const BUNDLE_FILE = 'bundle-v1.bin';

    private const SCOPE_FILE = '/app/index.php';

    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null) {
            FileSystem::delete($this->tmpDir);
        }
    }

    public function testWriteAndReadRoundTrip(): void
    {
        $cache = new UsageCacheStorage($this->getTmpDir(), offloadCollectorData: true);

        $usages = [$this->createUsage(10), $this->createUsage(15)];
        $hashes = $cache->pack($usages, self::SCOPE_FILE);

        self::assertCount(1, $hashes);

        $restored = $cache->unpack($hashes[0], self::SCOPE_FILE);

        self::assertEquals($usages, $restored);
    }

    public function testWriteReturnsSameHashForSameData(): void
    {
        $cache = new UsageCacheStorage($this->getTmpDir(), offloadCollectorData: true);

        $usages = [$this->createUsage(10)];

        $hashes1 = $cache->pack($usages, self::SCOPE_FILE);
        $hashes2 = $cache->pack($usages, self::SCOPE_FILE);

        self::assertSame($hashes1, $hashes2);
    }

    public function testReadMissingHashThrows(): void
    {
        $cache = new UsageCacheStorage($this->getTmpDir(), offloadCollectorData: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DCD cache entry not found');

        $cache->unpack('nonexistenthash', self::SCOPE_FILE);
    }

    public function testDisabledCachePassesDataThrough(): void
    {
        $cache = new UsageCacheStorage($this->getTmpDir(), offloadCollectorData: false);

        $usages = [$this->createUsage(10), $this->createUsage(15)];
        $strings = $cache->pack($usages, self::SCOPE_FILE);

        self::assertCount(2, $strings);

        $restored = [];

        foreach ($strings as $string) {
            foreach ($cache->unpack($string, self::SCOPE_FILE) as $usage) {
                $restored[] = $usage;
            }
        }

        self::assertEquals($usages, $restored);
    }

    public function testSerializedUsageContainsNoNewline(): void
    {
        $serialized = $this->createUsage(10)->serialize(self::SCOPE_FILE);

        self::assertStringNotContainsString("\n", $serialized, 'Serialized usage must not contain newlines (used as separator in cache files)');
    }

    public function testGcCompactsUnreadEntriesAway(): void
    {
        $tmpDir = $this->getTmpDir();
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        $hash1 = $cache->pack([$this->createUsage(10)], self::SCOPE_FILE);
        $hash2 = $cache->pack([$this->createUsage(15)], self::SCOPE_FILE);

        // only hash1 is read, so hash2 becomes garbage exceeding the compaction threshold
        $cache->unpack($hash1[0], self::SCOPE_FILE);
        $cache->gc();

        self::assertSame([self::BUNDLE_FILE], $this->listCacheDir($tmpDir));

        // the compacted bundle is laid out in read order
        $bundleContent = file_get_contents($tmpDir . '/dcd/' . self::BUNDLE_FILE);
        self::assertNotFalse($bundleContent);
        self::assertSame($hash1[0], substr($bundleContent, 0, 32));

        $freshCache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $restored = $freshCache->unpack($hash1[0], self::SCOPE_FILE);
        self::assertCount(1, $restored);

        $this->expectException(LogicException::class);
        $freshCache->unpack($hash2[0], self::SCOPE_FILE);
    }

    public function testMergesLogsOfMultipleInstances(): void
    {
        $tmpDir = $this->getTmpDir();

        $workerA = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $workerB = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        $usage1 = $this->createUsage(10);
        $usage2 = $this->createUsage(15);

        $hash1 = $workerA->pack([$usage1], self::SCOPE_FILE);
        $hash2 = $workerB->pack([$usage2], self::SCOPE_FILE);
        unset($workerA, $workerB);

        $finalizer = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        self::assertEquals([$usage1], $finalizer->unpack($hash1[0], self::SCOPE_FILE));
        self::assertEquals([$usage2], $finalizer->unpack($hash2[0], self::SCOPE_FILE));

        $finalizer->gc();

        $freshCache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        self::assertEquals([$usage1], $freshCache->unpack($hash1[0], self::SCOPE_FILE));
        self::assertEquals([$usage2], $freshCache->unpack($hash2[0], self::SCOPE_FILE));
        self::assertSame([self::BUNDLE_FILE], $this->listCacheDir($tmpDir));
    }

    public function testAppendsToBundleWithoutRewrite(): void
    {
        $tmpDir = $this->getTmpDir();
        $bundlePath = $tmpDir . '/dcd/' . self::BUNDLE_FILE;

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash1 = $cache->pack([$this->createUsage(10)], self::SCOPE_FILE);
        $cache->unpack($hash1[0], self::SCOPE_FILE);
        $cache->gc();

        $sizeAfterFirstRun = filesize($bundlePath);
        self::assertNotFalse($sizeAfterFirstRun);

        // second run reads everything and adds one entry: the bundle grows by exactly one record
        $newContent = $this->createUsage(15)->serialize(self::SCOPE_FILE);

        $nextRun = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash2 = $nextRun->pack([$this->createUsage(15)], self::SCOPE_FILE);
        $nextRun->unpack($hash1[0], self::SCOPE_FILE);
        $nextRun->unpack($hash2[0], self::SCOPE_FILE);
        $nextRun->gc();

        self::assertSame($sizeAfterFirstRun + 36 + strlen($newContent), filesize($bundlePath));
        self::assertSame([self::BUNDLE_FILE], $this->listCacheDir($tmpDir));
    }

    public function testRecoversFromTruncatedBundleTail(): void
    {
        $tmpDir = $this->getTmpDir();

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash1 = $cache->pack([$this->createUsage(10)], self::SCOPE_FILE);
        $cache->unpack($hash1[0], self::SCOPE_FILE);
        $cache->gc();

        // a killed process can leave an incomplete record at the end of the bundle
        file_put_contents($tmpDir . '/dcd/' . self::BUNDLE_FILE, 'incomplete-record', FILE_APPEND);

        $nextRun = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash2 = $nextRun->pack([$this->createUsage(15)], self::SCOPE_FILE);

        self::assertCount(1, $nextRun->unpack($hash1[0], self::SCOPE_FILE));
        self::assertCount(1, $nextRun->unpack($hash2[0], self::SCOPE_FILE));

        $nextRun->gc();

        $freshCache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        self::assertCount(1, $freshCache->unpack($hash1[0], self::SCOPE_FILE));
        self::assertCount(1, $freshCache->unpack($hash2[0], self::SCOPE_FILE));
    }

    public function testPackAfterUnpackIsRefused(): void
    {
        $cache = new UsageCacheStorage($this->getTmpDir(), offloadCollectorData: true);

        $hash = $cache->pack([$this->createUsage(10)], self::SCOPE_FILE);
        $cache->unpack($hash[0], self::SCOPE_FILE);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('pack() called after unpack()');

        $cache->pack([$this->createUsage(15)], self::SCOPE_FILE);
    }

    public function testGcRemovesFilesOfOlderReleases(): void
    {
        $tmpDir = $this->getTmpDir();

        // loose files of pre-bundle releases + bundle files of a hypothetical older format
        mkdir($tmpDir . '/dcd/ab', 0777, true);
        file_put_contents($tmpDir . '/dcd/ab/cdef0123.dat', 'legacy loose file');
        file_put_contents($tmpDir . '/dcd/bundle.dat', 'legacy bundle');
        file_put_contents($tmpDir . '/dcd/bundle.idx', 'legacy index');

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash = $cache->pack([$this->createUsage(10)], self::SCOPE_FILE);
        $cache->unpack($hash[0], self::SCOPE_FILE);
        $cache->gc();

        self::assertSame([self::BUNDLE_FILE], $this->listCacheDir($tmpDir));
    }

    private function createUsage(int $line): CollectedUsage
    {
        return new CollectedUsage(
            new ClassMethodUsage(
                new UsageOrigin(className: 'App\Foo', memberName: 'bar', memberType: MemberType::METHOD, accessType: AccessType::READ, fileName: self::SCOPE_FILE, line: $line, provider: null, note: null),
                new ClassMethodRef('App\Baz', 'qux', possibleDescendant: false),
            ),
            null,
        );
    }

    private function getTmpDir(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/dcd-test-' . uniqid();
        }

        return $this->tmpDir;
    }

    /**
     * @return list<string>
     */
    private function listCacheDir(string $tmpDir): array
    {
        $items = scandir($tmpDir . '/dcd');
        self::assertNotFalse($items);

        $result = [];

        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $result[] = $item;
            }
        }

        return $result;
    }

}

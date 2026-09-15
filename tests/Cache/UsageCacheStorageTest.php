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
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function glob;
use function is_dir;
use function rmdir;
use function substr;
use function sys_get_temp_dir;
use function unlink;

final class UsageCacheStorageTest extends TestCase
{

    public function testWriteAndReadRoundTrip(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', offloadCollectorData: true);

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
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test', offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hashes1 = $cache->pack($usages, $scopeFile);
        $hashes2 = $cache->pack($usages, $scopeFile);

        self::assertSame($hashes1, $hashes2);
    }

    public function testReadMissingHashThrows(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-missing', offloadCollectorData: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DCD cache file not found');

        $cache->unpack('nonexistenthash', '/app/index.php');
    }

    public function testDisabledCachePassesDataThrough(): void
    {
        $cache = new UsageCacheStorage(sys_get_temp_dir() . '/dcd-test-disabled', offloadCollectorData: false);

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
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);

        $scopeFile = '/app/index.php';
        $usages = $this->createSampleUsages();

        $hash1 = $cache->pack([$usages[0]], $scopeFile);
        $hash2 = $cache->pack([$usages[1]], $scopeFile);

        // Only read hash1, so hash2 should be cleaned up
        $cache->unpack($hash1[0], $scopeFile);
        $cache->gc();

        // hash1 should still be readable by a fresh instance
        $freshCache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $restored = $freshCache->unpack($hash1[0], $scopeFile);
        self::assertCount(1, $restored);

        // hash2 should be gone
        $this->expectException(LogicException::class);
        $freshCache->unpack($hash2[0], $scopeFile);
    }

    public function testGcBundlesReadFilesAndServesThemToNextRun(): void
    {
        $tmpDir = $this->freshTmpDir('bundle');
        $scopeFile = '/app/index.php';
        [$usage1, $usage2] = $this->createSampleUsages();

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash1 = $cache->pack([$usage1], $scopeFile)[0];
        $cache->unpack($hash1, $scopeFile);
        $cache->gc();

        self::assertFileExists($tmpDir . '/dcd/bundle.dat');
        self::assertFileExists($tmpDir . '/dcd/bundle.idx');
        self::assertFileDoesNotExist($tmpDir . '/dcd/' . substr($hash1, 0, 2) . '/' . substr($hash1, 2) . '.dat');

        // second run: hash1 comes from the bundle, hash2 is new and gets appended
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash2 = $cache->pack([$usage2], $scopeFile)[0];
        self::assertCount(1, $cache->unpack($hash1, $scopeFile));
        self::assertCount(1, $cache->unpack($hash2, $scopeFile));
        $cache->gc();

        self::assertSame([], glob($tmpDir . '/dcd/*/*.dat'));

        // third run: only hash2 is read, hash1 becomes 50% garbage and the bundle is compacted
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        self::assertCount(1, $cache->unpack($hash2, $scopeFile));
        $cache->gc();

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        self::assertCount(1, $cache->unpack($hash2, $scopeFile));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DCD cache file not found');
        $cache->unpack($hash1, $scopeFile);
    }

    public function testCorruptIndexThrows(): void
    {
        $tmpDir = $this->freshTmpDir('corrupt-idx');
        $scopeFile = '/app/index.php';

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash = $cache->pack([$this->createSampleUsages()[0]], $scopeFile)[0];
        $cache->unpack($hash, $scopeFile);
        $cache->gc();

        file_put_contents($tmpDir . '/dcd/bundle.idx', 'garbage');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is corrupt (unexpected header)');
        (new UsageCacheStorage($tmpDir, offloadCollectorData: true))->unpack($hash, $scopeFile);
    }

    public function testIndexFromAnotherBundleGenerationThrows(): void
    {
        $tmpDir = $this->freshTmpDir('generation');
        $scopeFile = '/app/index.php';

        [$usage1, $usage2] = $this->createSampleUsages();

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash1 = $cache->pack([$usage1], $scopeFile)[0];
        $hash2 = $cache->pack([$usage2], $scopeFile)[0];
        $cache->unpack($hash1, $scopeFile);
        $cache->unpack($hash2, $scopeFile);
        $cache->gc();
        $staleIndex = file_get_contents($tmpDir . '/dcd/bundle.idx');

        // reading only hash2 leaves 50% garbage, so gc rewrites the bundle under a new generation;
        // putting the old index back simulates a crash between the data rename and the index write
        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $cache->unpack($hash2, $scopeFile);
        $cache->gc();
        file_put_contents($tmpDir . '/dcd/bundle.idx', $staleIndex);
        $hash = $hash2;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('different bundle generation');
        (new UsageCacheStorage($tmpDir, offloadCollectorData: true))->unpack($hash, $scopeFile);
    }

    public function testTruncatedBundleThrows(): void
    {
        $tmpDir = $this->freshTmpDir('truncated');
        $scopeFile = '/app/index.php';

        $cache = new UsageCacheStorage($tmpDir, offloadCollectorData: true);
        $hash = $cache->pack([$this->createSampleUsages()[0]], $scopeFile)[0];
        $cache->unpack($hash, $scopeFile);
        $cache->gc();

        $bundle = $tmpDir . '/dcd/bundle.dat';
        file_put_contents($bundle, substr((string) file_get_contents($bundle), 0, -10));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('shorter than the index claims');
        (new UsageCacheStorage($tmpDir, offloadCollectorData: true))->unpack($hash, $scopeFile);
    }

    private function freshTmpDir(string $name): string
    {
        $tmpDir = sys_get_temp_dir() . '/dcd-test-' . $name . '-' . getmypid();

        foreach ($this->glob($tmpDir . '/dcd/*/*.dat') as $file) {
            unlink($file);
        }

        foreach ($this->glob($tmpDir . '/dcd/*') as $entry) {
            if (is_dir($entry)) {
                rmdir($entry);
            } else {
                unlink($entry);
            }
        }

        return $tmpDir;
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
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

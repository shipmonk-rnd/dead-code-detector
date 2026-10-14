<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use PHPUnit\Framework\TestCase;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;

final class StaleResultCacheDetectorTest extends TestCase
{

    public function testNotStaleWithoutUsageCache(): void
    {
        $tmpDir = $this->createTmpDir();
        $this->writeResultCache($tmpDir, 'data', 1_000);

        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale());
        self::assertDirectoryDoesNotExist($tmpDir . '/dcd');
    }

    public function testNotStaleWithoutResultCache(): void
    {
        $tmpDir = $this->createTmpDir();
        mkdir($tmpDir . '/dcd');

        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale());
        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale());
    }

    public function testStaleUntilResultCacheIsRewritten(): void
    {
        $tmpDir = $this->createTmpDir();
        mkdir($tmpDir . '/dcd');

        $this->writeResultCache($tmpDir, 'data', 1_000);
        self::assertTrue($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The writer of the first seen result cache is unknown');
        self::assertTrue($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The run did not write the result cache');

        $this->writeResultCache($tmpDir, 'data', 1_001);
        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The run wrote the result cache at a later time');
        self::assertTrue($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The run did not write the result cache');

        $this->writeResultCache($tmpDir, 'longer data', 1_001);
        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The run wrote a result cache of another size');
        self::assertTrue($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The run did not write the result cache');

        unlink($tmpDir . '/resultCache.php');
        self::assertFalse($this->createDetector($tmpDir)->canResultCacheBeStale(), 'No result cache exists');

        $this->writeResultCache($tmpDir, 'longer data', 1_001);
        self::assertTrue($this->createDetector($tmpDir)->canResultCacheBeStale(), 'The result cache is equal to the last seen one');
    }

    private function createDetector(string $tmpDir): StaleResultCacheDetector
    {
        return new StaleResultCacheDetector($tmpDir, $tmpDir . '/resultCache.php');
    }

    private function writeResultCache(
        string $tmpDir,
        string $content,
        int $modificationTime,
    ): void
    {
        $resultCachePath = $tmpDir . '/resultCache.php';

        self::assertNotFalse(file_put_contents($resultCachePath, $content));
        self::assertTrue(touch($resultCachePath, $modificationTime));
    }

    private function createTmpDir(): string
    {
        $tmpDir = sys_get_temp_dir() . '/' . uniqid('dcd-test-stale-result-cache-', true);
        self::assertTrue(mkdir($tmpDir));

        return $tmpDir;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function is_dir;
use function is_file;
use function rename;

/**
 * PHPStan does not tell extensions if it saved the result cache.
 * It does not save it in editor mode or when an analysed file has a syntax error, for example.
 * The result cache on disk is then stale: it still references usage cache files of an older run.
 */
final class StaleResultCacheDetector
{

    private readonly string $usageCacheDir;

    private readonly string $resultCachePath;

    public function __construct(
        string $tmpDir,
        string $resultCachePath,
    )
    {
        $this->usageCacheDir = $tmpDir . '/dcd';
        $this->resultCachePath = $resultCachePath;
    }

    /**
     * Returns false when PHPStan wrote the result cache after the previous call. The previous call can be in an earlier run.
     * Returns false also when no result cache or no usage cache exists.
     *
     * PHPStan saves the result cache before it runs the CollectedDataNode rules.
     * Call this method one time in each run, at the start of such a rule.
     */
    public function canResultCacheBeStale(): bool
    {
        if (!is_dir($this->usageCacheDir)) {
            return false;
        }

        $fingerprint = $this->getResultCacheFingerprint();

        if ($fingerprint === null) {
            return false;
        }

        $previousFingerprint = $this->readStamp();

        if ($fingerprint === $previousFingerprint) {
            return true;
        }

        $this->writeStamp($fingerprint);

        // the writer of the first seen result cache is unknown
        return $previousFingerprint === null;
    }

    private function getResultCacheFingerprint(): ?string
    {
        clearstatcache(true, $this->resultCachePath);

        if (!is_file($this->resultCachePath)) {
            return null;
        }

        $modificationTime = filemtime($this->resultCachePath);
        $size = filesize($this->resultCachePath);

        if ($modificationTime === false || $size === false) {
            throw new LogicException("Could not read state of PHPStan result cache: {$this->resultCachePath}");
        }

        // no inode: a result cache restored from a CI cache archive must keep its fingerprint
        return $modificationTime . ':' . $size;
    }

    private function readStamp(): ?string
    {
        $stampFile = $this->getStampFile();

        if (!is_file($stampFile)) {
            return null;
        }

        $stamp = file_get_contents($stampFile);

        if ($stamp === false) {
            throw new LogicException("Could not read DCD result cache stamp: {$stampFile}");
        }

        return $stamp;
    }

    private function writeStamp(string $fingerprint): void
    {
        $stampFile = $this->getStampFile();
        $temporaryFile = $stampFile . '.tmp';

        // rename is atomic, an interrupted run cannot leave a partial stamp
        if (file_put_contents($temporaryFile, $fingerprint) === false || !rename($temporaryFile, $stampFile)) {
            throw new LogicException("Failed to write DCD result cache stamp: {$stampFile}");
        }
    }

    private function getStampFile(): string
    {
        return $this->usageCacheDir . '/result-cache.stamp';
    }

}

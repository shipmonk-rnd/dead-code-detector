<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use DirectoryIterator;
use LogicException;
use RuntimeException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_intersect_key;
use function array_map;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function md5;
use function mkdir;
use function rename;
use function rmdir;
use function serialize;
use function substr;
use function uniqid;
use function unlink;
use function unserialize;

/**
 * Workers write one content-addressed loose file per pack() call; that needs no locking and
 * dedupes identical payloads for free. The first unpack() loads the map file and merges all
 * loose files into it. gc() drops the entries that the current run did not read, rewrites
 * the map file when its content changed and deletes everything else in the cache directory.
 *
 * One map file replaces tens of thousands of small files, whose open() calls dominated the
 * read cost. The price is that all offloaded data lives in memory at once during the rule phase.
 */
final class UsageCacheStorage
{

    private const MAP_FILE = 'map-v1.dat';

    private readonly string $cacheDir;

    private readonly bool $offloadCollectorData;

    /**
     * @var array<string, true>
     */
    private array $readHashes = [];

    /**
     * hash => newline-joined serialized usages
     *
     * @var array<string, string>|null
     */
    private ?array $entries = null;

    private bool $looseFilesMerged = false;

    public function __construct(
        string $tmpDir,
        bool $offloadCollectorData,
    )
    {
        $this->cacheDir = $tmpDir . '/dcd';
        $this->offloadCollectorData = $offloadCollectorData;
    }

    /**
     * @param non-empty-list<CollectedUsage> $usages
     * @return non-empty-list<string>
     */
    public function pack(
        array $usages,
        string $scopeFile,
    ): array
    {
        $serialized = array_map(
            static fn (CollectedUsage $usage): string => $usage->serialize($scopeFile),
            $usages,
        );

        if (!$this->offloadCollectorData) {
            return $serialized;
        }

        if ($this->entries !== null) {
            throw new LogicException('DCD cache misuse: pack() called after unpack(); collectors always run before the rule.');
        }

        $content = implode("\n", $serialized);
        $hash = md5($content);

        $filePath = $this->getFilePath($hash);

        if (!file_exists($filePath)) {
            $this->ensureDirectoryExists($hash);

            if (file_put_contents($filePath, $content) === false) {
                throw new LogicException("Failed to write DCD cache file: {$filePath}");
            }
        }

        return [$hash];
    }

    /**
     * @return non-empty-list<CollectedUsage>
     */
    public function unpack(
        string $data,
        string $scopeFile,
    ): array
    {
        if (!$this->offloadCollectorData) {
            return [CollectedUsage::deserialize($data, $scopeFile)];
        }

        $this->readHashes[$data] = true;

        $content = ($this->entries ??= $this->loadEntries())[$data] ?? null;

        if ($content === null) {
            throw new LogicException(
                "DCD cache entry not found for hash '{$data}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        return array_map(
            static fn (string $line): CollectedUsage => CollectedUsage::deserialize($line, $scopeFile),
            explode("\n", $content),
        );
    }

    /**
     * Drop entries not read by this run, then delete everything but the map file in the cache directory.
     *
     * Intentionally not guarded by offloadCollectorData: with offloading disabled, nothing is read,
     * so a previously offloading run leaves no stale data behind once the option gets disabled.
     */
    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $entries = $this->entries ?? [];
        $survivors = array_intersect_key($entries, $this->readHashes);

        if ($survivors === []) {
            @unlink($this->getMapPath());
        } elseif ($this->looseFilesMerged || count($survivors) !== count($entries)) {
            $this->writeMap($survivors);
        }

        $this->deleteAllExceptMap();

        // a long-lived process may run another analysis pass producing new loose files
        $this->entries = null;
        $this->looseFilesMerged = false;
    }

    /**
     * @return array<string, string>
     */
    private function loadEntries(): array
    {
        $entries = $this->readMap();

        foreach ($this->findLooseFiles() as $hash => $filePath) {
            if (isset($entries[$hash])) {
                continue;
            }

            $content = @file_get_contents($filePath);

            if ($content === false) {
                continue;
            }

            $entries[$hash] = $content;
            $this->looseFilesMerged = true;
        }

        return $entries;
    }

    /**
     * A missing or corrupted map degrades to an empty one. A hash it should have contained
     * then produces the "clear the result cache" error in unpack().
     *
     * @return array<string, string>
     */
    private function readMap(): array
    {
        $raw = @file_get_contents($this->getMapPath());

        if ($raw === false) {
            return [];
        }

        $map = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($map)) {
            return [];
        }

        /** @var array<string, string> $map */
        return $map;
    }

    /**
     * @param non-empty-array<string, string> $entries
     */
    private function writeMap(array $entries): void
    {
        $mapPath = $this->getMapPath();
        $tmpPath = $mapPath . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($tmpPath, serialize($entries)) === false || @rename($tmpPath, $mapPath) === false) {
            @unlink($tmpPath);
            throw new LogicException("Failed to write DCD cache map: {$mapPath}");
        }
    }

    /**
     * @return iterable<string, string> hash => file path
     */
    private function findLooseFiles(): iterable
    {
        try {
            $subdirs = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return;
        }

        foreach ($subdirs as $subdir) {
            if ($subdir->isDot() || !$subdir->isDir()) {
                continue;
            }

            try {
                $files = new DirectoryIterator($subdir->getPathname());
            } catch (RuntimeException $e) {
                continue;
            }

            foreach ($files as $file) {
                if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'dat') {
                    continue;
                }

                yield $subdir->getFilename() . $file->getBasename('.dat') => $file->getPathname();
            }
        }
    }

    private function deleteAllExceptMap(): void
    {
        try {
            $items = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return;
        }

        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
            } elseif ($item->getFilename() !== self::MAP_FILE) {
                @unlink($item->getPathname());
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        try {
            $files = new DirectoryIterator($dir);
        } catch (RuntimeException $e) {
            return;
        }

        foreach ($files as $file) {
            if (!$file->isDot() && $file->isFile()) {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function getMapPath(): string
    {
        return $this->cacheDir . '/' . self::MAP_FILE;
    }

    private function getFilePath(string $hash): string
    {
        $prefix = substr($hash, 0, 2);

        return $this->cacheDir . '/' . $prefix . '/' . substr($hash, 2) . '.dat';
    }

    private function ensureDirectoryExists(string $hash): void
    {
        $dir = $this->cacheDir . '/' . substr($hash, 0, 2);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

}

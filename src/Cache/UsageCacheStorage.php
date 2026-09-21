<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;
use function explode;
use function file_exists;
use function implode;
use function is_dir;
use function md5;
use function unlink;

/**
 * Workers pack() usages into loose files. The finalizer unpack()s them, preferring the
 * bundle, and gc() then folds everything this run read into the bundle for the next run.
 */
final class UsageCacheStorage
{

    private const BUNDLE_DATA_FILE = 'bundle.dat';

    private const BUNDLE_INDEX_FILE = 'bundle.idx';

    /**
     * Rewriting the whole bundle only pays off once enough of it became garbage.
     */
    private const GARBAGE_RATIO_LIMIT = 0.2;

    private readonly string $cacheDir;

    private readonly bool $offloadCollectorData;

    private readonly LooseFileStore $looseFiles;

    private readonly BundleFile $bundle;

    private ?BundleIndex $index = null;

    /**
     * Insertion ordered, so it doubles as the read order for laying out the next bundle.
     *
     * @var array<string, true>
     */
    private array $readHashes = [];

    public function __construct(
        string $tmpDir,
        bool $offloadCollectorData,
    )
    {
        $this->cacheDir = $tmpDir . '/dcd';
        $this->offloadCollectorData = $offloadCollectorData;
        $this->looseFiles = new LooseFileStore($this->cacheDir);
        $this->bundle = new BundleFile($this->cacheDir . '/' . self::BUNDLE_DATA_FILE);
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

        $content = implode("\n", $serialized);
        $hash = md5($content);

        $this->looseFiles->write($hash, $content);

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

        try {
            $position = $this->index()->get($data);
            $content = $position === null
                ? $this->looseFiles->read($data)
                : $this->bundle->read($position);
        } catch (CorruptUsageCacheException $e) {
            throw $this->discard($e);
        }

        if ($content === null) {
            throw new LogicException(
                "DCD cache file not found for hash '{$data}' at '{$this->looseFiles->path($data)}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        return array_map(
            static fn (string $line): CollectedUsage => CollectedUsage::deserialize($line, $scopeFile),
            explode("\n", $content),
        );
    }

    /**
     * Delete everything that was not read by this run, then merge what survived into the
     * bundle so that the next run does not have to open one file per hash.
     */
    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        try {
            $this->foldIntoBundle();
        } catch (CorruptUsageCacheException $e) {
            throw $this->discard($e);
        }
    }

    /**
     * @throws CorruptUsageCacheException
     */
    private function foldIntoBundle(): void
    {
        $index = $this->index();
        $unbundled = [];

        foreach ($this->looseFiles->findAll() as $hash) {
            if (!isset($this->readHashes[$hash]) || $index->has($hash)) {
                $this->looseFiles->remove($hash);
                continue;
            }

            $unbundled[$hash] = true;
        }

        $needsRewrite = $index->isEmpty() || $index->garbageRatio($this->readHashes) > self::GARBAGE_RATIO_LIMIT;

        if ($needsRewrite) {
            $newIndex = $this->bundle->rewrite($this->survivingRecords($index, $unbundled));
        } elseif ($unbundled !== []) {
            $newIndex = $this->bundle->append($this->looseRecords($unbundled), $index);
        } else {
            $newIndex = null;
        }

        if ($newIndex !== null) {
            $newIndex->save($this->cacheDir . '/' . self::BUNDLE_INDEX_FILE);

            foreach ($unbundled as $hash => $unused) {
                if ($newIndex->has($hash)) {
                    $this->looseFiles->remove($hash);
                }
            }
        }

        $this->looseFiles->removeEmptyDirectories();
        $this->bundle->close();
        $this->index = null;
    }

    /**
     * @throws CorruptUsageCacheException
     */
    private function index(): BundleIndex
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = BundleIndex::load($this->cacheDir . '/' . self::BUNDLE_INDEX_FILE);

        if (!$index->isEmpty() && $index->getGeneration() !== $this->bundle->getGeneration()) {
            throw new CorruptUsageCacheException("DCD usage cache index in '{$this->cacheDir}' belongs to a different bundle generation.");
        }

        return $this->index = $index;
    }

    /**
     * The next run then starts without a bundle and reads the loose files that a result
     * cache clear makes the collectors write again.
     */
    private function discard(CorruptUsageCacheException $e): LogicException
    {
        $this->bundle->close();
        $this->index = null;

        foreach ([self::BUNDLE_DATA_FILE, self::BUNDLE_INDEX_FILE] as $file) {
            $path = $this->cacheDir . '/' . $file;

            if (file_exists($path) && !unlink($path)) {
                throw new LogicException("Failed to delete corrupt DCD usage cache file '{$path}'.", 0, $e);
            }
        }

        return new LogicException(
            $e->getMessage() . ' The bundle was discarded. Clear the PHPStan result cache and re-run the analysis.',
            0,
            $e,
        );
    }

    /**
     * Everything this run read, in read order, so that the next run reads the bundle front to back.
     *
     * @param array<string, true> $unbundled hashes that exist only as loose files
     * @return iterable<string, string> hash => content
     *
     * @throws CorruptUsageCacheException
     */
    private function survivingRecords(
        BundleIndex $index,
        array $unbundled,
    ): iterable
    {
        foreach ($this->readHashes as $hash => $unused) {
            if (isset($unbundled[$hash])) {
                yield $hash => $this->requireLooseRecord($hash);
                continue;
            }

            $position = $index->get($hash);

            if ($position === null) {
                continue; // an oversized record read from its loose file, stays loose
            }

            yield $hash => $this->bundle->read($position);
        }
    }

    /**
     * @param array<string, true> $hashes
     * @return iterable<string, string> hash => content
     */
    private function looseRecords(array $hashes): iterable
    {
        foreach ($hashes as $hash => $unused) {
            yield $hash => $this->requireLooseRecord($hash);
        }
    }

    /**
     * The file was listed by this very gc() run, so it can only be gone if another process removed it.
     */
    private function requireLooseRecord(string $hash): string
    {
        $content = $this->looseFiles->read($hash);

        if ($content === null) {
            throw new LogicException(
                "DCD cache file '{$this->looseFiles->path($hash)}' disappeared during gc. "
                . 'Is another PHPStan process sharing the same tmpDir?',
            );
        }

        return $content;
    }

}

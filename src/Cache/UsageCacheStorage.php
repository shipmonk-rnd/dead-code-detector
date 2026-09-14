<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;
use function explode;
use function implode;
use function is_dir;
use function md5;

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

        $content = $this->readRecord($data) ?? $this->looseFiles->read($data);

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

        $index = $this->index();
        $unbundled = [];

        foreach ($this->looseFiles->findAll() as $hash) {
            if (!isset($this->readHashes[$hash]) || $index->has($hash)) {
                $this->looseFiles->remove($hash);
                continue;
            }

            $unbundled[$hash] = true;
        }

        $newIndex = null;

        if ($index->garbageRatio($this->readHashes) > self::GARBAGE_RATIO_LIMIT || ($unbundled !== [] && $this->bundle->isEmpty())) {
            $newIndex = $this->bundle->rewrite($this->survivingRecords($index, $unbundled));
        } elseif ($unbundled !== []) {
            $newIndex = $this->bundle->append($this->looseRecords($unbundled), $index);
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

    private function readRecord(string $hash): ?string
    {
        $position = $this->index()->get($hash);

        return $position === null ? null : $this->bundle->read($position);
    }

    private function index(): BundleIndex
    {
        return $this->index ??= BundleIndex::load($this->cacheDir . '/' . self::BUNDLE_INDEX_FILE);
    }

    /**
     * Everything this run read, in read order, so that the next run reads the bundle front to back.
     *
     * @param array<string, true> $unbundled hashes that exist only as loose files
     * @return iterable<string, string> hash => content
     */
    private function survivingRecords(
        BundleIndex $index,
        array $unbundled,
    ): iterable
    {
        foreach ($this->readHashes as $hash => $unused) {
            $content = isset($unbundled[$hash]) ? $this->looseFiles->read($hash) : $this->readRecordFrom($index, $hash);

            if ($content !== null) {
                yield $hash => $content;
            }
        }
    }

    /**
     * @param array<string, true> $hashes
     * @return iterable<string, string> hash => content
     */
    private function looseRecords(array $hashes): iterable
    {
        foreach ($hashes as $hash => $unused) {
            $content = $this->looseFiles->read($hash);

            if ($content !== null) {
                yield $hash => $content;
            }
        }
    }

    private function readRecordFrom(
        BundleIndex $index,
        string $hash,
    ): ?string
    {
        $position = $index->get($hash);

        return $position === null ? null : $this->bundle->read($position);
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use DirectoryIterator;
use LogicException;
use RuntimeException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;
use function clearstatcache;
use function count;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function getmypid;
use function implode;
use function intdiv;
use function is_dir;
use function is_int;
use function md5;
use function mkdir;
use function pack;
use function rename;
use function rmdir;
use function str_split;
use function strlen;
use function substr;
use function unlink;
use function unpack;
use const LOCK_EX;

final class UsageCacheStorage
{

    private const BUNDLE_DATA_FILE = 'bundle.dat';

    private const BUNDLE_INDEX_FILE = 'bundle.idx';

    private const BUNDLE_INDEX_MAGIC = 'DCD1';

    /**
     * Each index entry is a 32 char hex hash plus a 64bit packed position.
     */
    private const BUNDLE_INDEX_ENTRY_SIZE = 40;

    /**
     * Offset and length of an entry share a single int so that the position block can be
     * decoded by a single unpack() call.
     */
    private const BUNDLE_LENGTH_BITS = 24;

    private const BUNDLE_ENTRY_SIZE_LIMIT = (1 << self::BUNDLE_LENGTH_BITS) - 1;

    /**
     * Rewriting the whole bundle only pays off once enough of it became garbage.
     */
    private const BUNDLE_GARBAGE_RATIO_LIMIT = 0.2;

    private readonly string $cacheDir;

    private readonly bool $offloadCollectorData;

    /**
     * @var array<string, true>
     */
    private array $readHashes = [];

    /**
     * hash => (offset << BUNDLE_LENGTH_BITS) | length
     *
     * @var array<string, int>|null
     */
    private ?array $bundleIndex = null;

    /**
     * @var resource|null
     */
    private $bundleHandle = null;

    private ?int $bundleHandlePid = null;

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

        $content = $this->readFromBundle($data);

        if ($content === null) {
            $filePath = $this->getFilePath($data);
            $content = @file_get_contents($filePath);

            if ($content === false) {
                throw new LogicException(
                    "DCD cache file not found for hash '{$data}' at '{$filePath}'. "
                    . 'Please clear the PHPStan result cache and re-run the analysis.',
                );
            }
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

        $looseFiles = [];

        foreach ($this->findLooseFiles() as $hash => $path) {
            if (!isset($this->readHashes[$hash])) {
                @unlink($path);
                continue;
            }

            if (isset($this->loadBundleIndex()[$hash])) {
                @unlink($path); // already bundled, the loose copy is redundant
                continue;
            }

            $looseFiles[$hash] = $path;
        }

        $index = $this->loadBundleIndex();
        $garbage = 0;

        foreach ($index as $hash => $position) {
            if (!isset($this->readHashes[$hash])) {
                $garbage++;
            }
        }

        if ($index !== [] && $garbage / count($index) > self::BUNDLE_GARBAGE_RATIO_LIMIT) {
            $this->compactBundle($looseFiles);
        } elseif ($looseFiles !== []) {
            $this->appendToBundle($looseFiles);
        }

        $this->removeEmptyDirectories();
        $this->closeBundle();
    }

    private function removeEmptyDirectories(): void
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

            @rmdir($subdir->getPathname());
        }
    }

    /**
     * Rewrites the bundle from scratch, keeping only entries read by this run.
     *
     * @param array<string, string> $looseFiles hash => file path
     */
    private function compactBundle(array $looseFiles): void
    {
        $index = $this->loadBundleIndex();

        // readHashes is insertion ordered, so laying the bundle out in that order makes
        // the next run read it front to back instead of seeking all over the file
        $sources = [];

        foreach ($this->readHashes as $hash => $read) {
            if (isset($looseFiles[$hash])) {
                $sources[$hash] = $looseFiles[$hash];
            } elseif (isset($index[$hash])) {
                $sources[$hash] = null; // read from the current bundle
            }
        }

        $dataPath = $this->cacheDir . '/' . self::BUNDLE_DATA_FILE;
        $tmpPath = $dataPath . '.tmp';

        $handle = @fopen($tmpPath, 'wb');

        if ($handle === false) {
            return;
        }

        $hashes = '';
        $positions = '';
        $offset = 0;
        $merged = [];

        foreach ($sources as $hash => $path) {
            $content = $path === null ? $this->readFromBundle($hash) : @file_get_contents($path);

            if ($content === null || $content === false || strlen($content) > self::BUNDLE_ENTRY_SIZE_LIMIT) {
                continue;
            }

            if (fwrite($handle, $content) === false) {
                fclose($handle);
                @unlink($tmpPath);
                return;
            }

            $hashes .= $hash;
            $positions .= pack('J', ($offset << self::BUNDLE_LENGTH_BITS) | strlen($content));
            $offset += strlen($content);
            $merged[$hash] = $path;
        }

        fclose($handle);
        $this->closeBundle();

        if (!@rename($tmpPath, $dataPath)) {
            @unlink($tmpPath);
            return;
        }

        $this->writeBundleIndex($hashes, $positions);
        $this->dropMergedFiles($merged);
    }

    /**
     * @param array<string, string> $looseFiles hash => file path
     */
    private function appendToBundle(array $looseFiles): void
    {
        $dataPath = $this->cacheDir . '/' . self::BUNDLE_DATA_FILE;

        clearstatcache(true, $dataPath);
        $size = file_exists($dataPath) ? filesize($dataPath) : 0;
        $offset = $size === false ? 0 : $size;

        if ($offset === 0) {
            $this->compactBundle($looseFiles);
            return;
        }

        $handle = @fopen($dataPath, 'ab');

        if ($handle === false) {
            return;
        }

        $hashes = '';
        $positions = '';
        $merged = [];

        foreach ($looseFiles as $hash => $path) {
            $content = @file_get_contents($path);

            if ($content === false || strlen($content) > self::BUNDLE_ENTRY_SIZE_LIMIT) {
                continue;
            }

            if (fwrite($handle, $content) === false) {
                break;
            }

            $hashes .= $hash;
            $positions .= pack('J', ($offset << self::BUNDLE_LENGTH_BITS) | strlen($content));
            $offset += strlen($content);
            $merged[$hash] = $path;
        }

        fclose($handle);

        if ($merged === []) {
            return;
        }

        // hashes and positions live in separate blocks, so appending means rewriting the
        // index; it is three orders of magnitude smaller than the data file
        $existingHashes = '';
        $existingPositions = '';

        foreach ($this->loadBundleIndex() as $hash => $position) {
            $existingHashes .= $hash;
            $existingPositions .= pack('J', $position);
        }

        $this->writeBundleIndex($existingHashes . $hashes, $existingPositions . $positions);
        $this->dropMergedFiles($merged);
    }

    private function writeBundleIndex(
        string $hashes,
        string $positions,
    ): void
    {
        @file_put_contents(
            $this->cacheDir . '/' . self::BUNDLE_INDEX_FILE,
            self::BUNDLE_INDEX_MAGIC . $hashes . $positions,
            LOCK_EX,
        );
    }

    /**
     * @param array<string, string|null> $merged hash => file path
     */
    private function dropMergedFiles(array $merged): void
    {
        foreach ($merged as $path) {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /**
     * @return array<string, string> hash => file path
     */
    private function findLooseFiles(): array
    {
        try {
            $subdirs = new DirectoryIterator($this->cacheDir);
        } catch (RuntimeException $e) {
            return [];
        }

        $result = [];

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
                if ($file->isDot() || $file->isDir()) {
                    continue;
                }

                $result[$subdir->getFilename() . $file->getBasename('.dat')] = $file->getPathname();
            }
        }

        return $result;
    }

    private function readFromBundle(string $hash): ?string
    {
        $position = $this->loadBundleIndex()[$hash] ?? null;

        if ($position === null) {
            return null;
        }

        $pid = getmypid();

        if ($this->bundleHandle === null || $pid === false || $this->bundleHandlePid !== $pid) {
            $handle = @fopen($this->cacheDir . '/' . self::BUNDLE_DATA_FILE, 'rb');

            if ($handle === false) {
                return null;
            }

            // a forked child inherits the parent descriptor together with its file
            // offset, so it must open its own instead of seeking in a shared one
            $this->bundleHandle = $handle;
            $this->bundleHandlePid = $pid === false ? null : $pid;
        }

        $length = $position & self::BUNDLE_ENTRY_SIZE_LIMIT;

        if ($length === 0) {
            return '';
        }

        fseek($this->bundleHandle, $position >> self::BUNDLE_LENGTH_BITS);
        $content = fread($this->bundleHandle, $length);

        if ($content === false || strlen($content) !== $length) {
            return null; // truncated bundle, fall back to the loose file
        }

        return $content;
    }

    /**
     * @return array<string, int>
     */
    private function loadBundleIndex(): array
    {
        if ($this->bundleIndex !== null) {
            return $this->bundleIndex;
        }

        $this->bundleIndex = [];

        $raw = @file_get_contents($this->cacheDir . '/' . self::BUNDLE_INDEX_FILE);

        if ($raw === false || substr($raw, 0, 4) !== self::BUNDLE_INDEX_MAGIC) {
            return $this->bundleIndex;
        }

        if ((strlen($raw) - 4) % self::BUNDLE_INDEX_ENTRY_SIZE !== 0) {
            return $this->bundleIndex;
        }

        $entries = intdiv(strlen($raw) - 4, self::BUNDLE_INDEX_ENTRY_SIZE);

        if ($entries === 0) {
            return $this->bundleIndex;
        }

        $hashes = str_split(substr($raw, 4, $entries * 32), 32);
        $positions = unpack('J*', substr($raw, 4 + ($entries * 32))); // one call for the whole block

        if ($positions === false) {
            return $this->bundleIndex;
        }

        $index = [];
        $position = 1; // unpack() numbers its results from one

        foreach ($hashes as $hash) {
            $offsetAndLength = $positions[$position] ?? null;
            $position++;

            if (!is_int($offsetAndLength)) {
                return $this->bundleIndex; // truncated index, everything falls back to loose files
            }

            $index[$hash] = $offsetAndLength;
        }

        $this->bundleIndex = $index;

        return $this->bundleIndex;
    }

    private function closeBundle(): void
    {
        if ($this->bundleHandle !== null) {
            fclose($this->bundleHandle);
            $this->bundleHandle = null;
            $this->bundleHandlePid = null;
        }

        $this->bundleIndex = null;
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

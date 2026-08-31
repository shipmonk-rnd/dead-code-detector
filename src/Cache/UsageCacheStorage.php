<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use DirectoryIterator;
use LogicException;
use RuntimeException;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_diff_key;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftruncate;
use function fwrite;
use function getmypid;
use function glob;
use function implode;
use function is_dir;
use function is_int;
use function md5;
use function mkdir;
use function pack;
use function rename;
use function rmdir;
use function stream_set_write_buffer;
use function strlen;
use function substr;
use function unlink;
use function unpack;
use const SEEK_CUR;

/**
 * Stores collector data outside PHPStan's result cache.
 *
 * All data lives in records of a single shape: 32 chars of md5 hash, uint32 content length, content.
 * pack() appends records to a log file owned by the current process, so parallel workers need no locking.
 * The first unpack() call merges all log records into a single bundle file and serves reads from it.
 * One bundle replaces tens of thousands of small files, whose open() calls dominated the read cost.
 *
 * gc() rewrites the bundle without entries that the current run did not read (only once their ratio
 * makes the rewrite pay off) and then deletes everything else in the cache directory.
 */
final class UsageCacheStorage
{

    private const BUNDLE_FILE = 'bundle-v1.bin';

    private const LOG_FILE_PREFIX = 'log-v1-';

    private const HASH_SIZE = 32;

    private const RECORD_HEADER_SIZE = self::HASH_SIZE + 4;

    /**
     * Rewriting the whole bundle only pays off once enough of it became garbage.
     */
    private const GARBAGE_RATIO_LIMIT = 0.2;

    private readonly string $cacheDir;

    private readonly bool $offloadCollectorData;

    /**
     * @var array<string, true>
     */
    private array $readHashes = [];

    /**
     * hash => [content offset within the bundle, content length]
     *
     * @var array<string, array{int<36, max>, int<1, max>}>|null
     */
    private ?array $entries = null;

    /**
     * @var resource|null
     */
    private $logHandle = null;

    private ?int $logHandlePid = null;

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

        if ($this->entries !== null) {
            throw new LogicException('DCD cache misuse: pack() called after unpack(); collectors always run before the rule.');
        }

        $content = implode("\n", $serialized);
        $hash = md5($content);
        $record = $hash . pack('N', strlen($content)) . $content;

        $handle = $this->getLogHandle();

        if (fwrite($handle, $record) !== strlen($record)) {
            throw new LogicException("Failed to write DCD cache log in '{$this->cacheDir}'.");
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

        $entry = $this->loadEntries()[$data] ?? null;

        if ($entry === null) {
            throw new LogicException(
                "DCD cache entry not found for hash '{$data}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        $content = $this->readBundle($entry[0], $entry[1]);

        if ($content === null) {
            throw new LogicException(
                "Could not read DCD cache bundle '{$this->getBundlePath()}'. "
                . 'Please clear the PHPStan result cache and re-run the analysis.',
            );
        }

        return array_map(
            static fn (string $line): CollectedUsage => CollectedUsage::deserialize($line, $scopeFile),
            explode("\n", $content),
        );
    }

    /**
     * Drop entries not read by this run, then delete everything but the bundle in the cache directory.
     *
     * Intentionally not guarded by offloadCollectorData: with offloading disabled, nothing is read,
     * so a leftover cache directory of previous runs gets emptied here.
     */
    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $entries = $this->loadEntries();
        $garbage = count(array_diff_key($entries, $this->readHashes));

        if ($garbage > count($entries) * self::GARBAGE_RATIO_LIMIT) {
            $this->compactBundle($entries);
        }

        $this->removeUnknownFiles();
        $this->close(); // a long-lived process may run another analysis pass producing new log files
    }

    /**
     * @return resource
     */
    private function getLogHandle()
    {
        $pid = $this->getPid();

        if ($this->logHandle !== null && $this->logHandlePid === $pid) {
            return $this->logHandle;
        }

        $this->closeLogHandle(); // a handle opened before a fork is shared with the parent

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }

        $path = $this->cacheDir . '/' . self::LOG_FILE_PREFIX . $pid . '.bin';
        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            throw new LogicException("Failed to write DCD cache log '{$path}'.");
        }

        stream_set_write_buffer($handle, 0); // each record must be fully written once pack() returns

        $this->logHandle = $handle;
        $this->logHandlePid = $pid;

        return $handle;
    }

    /**
     * Collectors always run before the rule, so all pack() calls of an analysis pass precede
     * the first unpack() and the merge sees all log records at once.
     *
     * @return array<string, array{int<36, max>, int<1, max>}>
     */
    private function loadEntries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $this->closeLogHandle();

        $entries = [];
        $bundleLength = 0;
        $handle = @fopen($this->getBundlePath(), 'rb');

        if ($handle !== false) {
            [$entries, $bundleLength] = $this->scanRecords($handle, $this->getFileSize($handle));
            fclose($handle);
        }

        $this->entries = $this->mergeLogs($entries, $bundleLength);

        return $this->entries;
    }

    /**
     * Append log records missing from the bundle to the bundle, then delete the logs.
     *
     * @param array<string, array{int<36, max>, int<1, max>}> $entries
     * @param int<0, max> $bundleLength
     * @return array<string, array{int<36, max>, int<1, max>}>
     */
    private function mergeLogs(
        array $entries,
        int $bundleLength,
    ): array
    {
        $logPaths = glob($this->cacheDir . '/' . self::LOG_FILE_PREFIX . '*.bin');

        if ($logPaths === false) {
            return $entries;
        }

        $bundleHandle = null;

        foreach ($logPaths as $logPath) {
            $logHandle = @fopen($logPath, 'rb');

            if ($logHandle === false) {
                continue;
            }

            $logSize = $this->getFileSize($logHandle);
            $position = 0;

            while ($logSize - $position >= self::RECORD_HEADER_SIZE) {
                $header = fread($logHandle, self::RECORD_HEADER_SIZE);
                $parsed = $this->parseRecordHeader($header);

                if ($parsed === null) {
                    break;
                }

                [$hash, $length] = $parsed;

                if ($position + self::RECORD_HEADER_SIZE + $length > $logSize) {
                    break;
                }

                $position += self::RECORD_HEADER_SIZE + $length;

                if (isset($entries[$hash])) {
                    fseek($logHandle, $length, SEEK_CUR);
                    continue;
                }

                $content = fread($logHandle, $length);

                if ($content === false || strlen($content) !== $length) {
                    break;
                }

                $bundleHandle ??= $this->openBundleForAppend($bundleLength);

                if (fwrite($bundleHandle, $header . $content) !== self::RECORD_HEADER_SIZE + $length) {
                    ftruncate($bundleHandle, $bundleLength); // drop the partial record, keep the complete ones
                    fclose($bundleHandle);
                    fclose($logHandle);

                    throw new LogicException("Failed to write DCD cache bundle '{$this->getBundlePath()}'.");
                }

                $entries[$hash] = [$bundleLength + self::RECORD_HEADER_SIZE, $length];
                $bundleLength += self::RECORD_HEADER_SIZE + $length;
            }

            fclose($logHandle);
            @unlink($logPath);
        }

        if ($bundleHandle !== null) {
            fclose($bundleHandle); // flush before readBundle() opens its own descriptor
        }

        return $entries;
    }

    /**
     * @param int<0, max> $bundleLength
     * @return resource
     */
    private function openBundleForAppend(int $bundleLength)
    {
        $path = $this->getBundlePath();
        $handle = @fopen($path, 'cb'); // mode 'a' ignores fseek(), but a truncated tail must be overwritten

        if ($handle === false) {
            throw new LogicException("Failed to write DCD cache bundle '{$path}'.");
        }

        ftruncate($handle, $bundleLength); // drop a truncated tail left behind by a killed process
        fseek($handle, $bundleLength);

        return $handle;
    }

    /**
     * Walk the records of a file, stopping at the first incomplete record
     * (a killed process can leave one at the end of a file).
     *
     * @param resource $handle
     * @return array{array<string, array{int<36, max>, int<1, max>}>, int<0, max>} [hash => [content offset, content length], count of valid bytes]
     */
    private function scanRecords(
        $handle,
        int $fileSize,
    ): array
    {
        $records = [];
        $position = 0;

        while ($fileSize - $position >= self::RECORD_HEADER_SIZE) {
            fseek($handle, $position);
            $parsed = $this->parseRecordHeader(fread($handle, self::RECORD_HEADER_SIZE));

            if ($parsed === null) {
                break;
            }

            [$hash, $length] = $parsed;

            if ($position + self::RECORD_HEADER_SIZE + $length > $fileSize) {
                break;
            }

            $records[$hash] = [$position + self::RECORD_HEADER_SIZE, $length];
            $position += self::RECORD_HEADER_SIZE + $length;
        }

        return [$records, $position];
    }

    /**
     * @return array{string, int<1, max>}|null [hash, content length]
     */
    private function parseRecordHeader(string|false $header): ?array
    {
        if ($header === false || strlen($header) !== self::RECORD_HEADER_SIZE) {
            return null;
        }

        $unpacked = unpack('N', $header, self::HASH_SIZE);
        $length = $unpacked === false ? 0 : ($unpacked[1] ?? 0);

        if (!is_int($length) || $length < 1) {
            return null;
        }

        return [substr($header, 0, self::HASH_SIZE), $length];
    }

    /**
     * @param int<0, max> $offset
     * @param int<1, max> $length
     */
    private function readBundle(
        int $offset,
        int $length,
    ): ?string
    {
        $pid = $this->getPid();

        if ($this->bundleHandle === null || $this->bundleHandlePid !== $pid) {
            $this->closeBundleHandle(); // a forked child inherits the descriptor together with its file offset

            $handle = @fopen($this->getBundlePath(), 'rb');

            if ($handle === false) {
                return null;
            }

            $this->bundleHandle = $handle;
            $this->bundleHandlePid = $pid;
        }

        return $this->readAt($this->bundleHandle, $offset, $length);
    }

    /**
     * @param resource $handle
     * @param int<0, max> $offset
     * @param int<1, max> $length
     */
    private function readAt(
        $handle,
        int $offset,
        int $length,
    ): ?string
    {
        fseek($handle, $offset);
        $content = fread($handle, $length);

        if ($content === false || strlen($content) !== $length) {
            return null;
        }

        return $content;
    }

    /**
     * Rewrite the bundle with the read entries only, laid out in read order:
     * the next run then reads the file front to back instead of seeking around.
     *
     * @param array<string, array{int<36, max>, int<1, max>}> $entries
     */
    private function compactBundle(array $entries): void
    {
        $bundlePath = $this->getBundlePath();
        $tmpPath = $bundlePath . '.tmp';
        $readHandle = @fopen($bundlePath, 'rb');

        if ($readHandle === false) {
            return;
        }

        $tmpHandle = @fopen($tmpPath, 'wb');

        if ($tmpHandle === false) {
            fclose($readHandle);

            return; // keep the uncompacted bundle
        }

        foreach (array_keys($this->readHashes) as $hash) {
            $entry = $entries[$hash] ?? null;

            if ($entry === null) {
                continue;
            }

            // the record starts one header before its content; copy it verbatim
            $record = $this->readAt($readHandle, $entry[0] - self::RECORD_HEADER_SIZE, $entry[1] + self::RECORD_HEADER_SIZE);

            if ($record === null || fwrite($tmpHandle, $record) !== strlen($record)) {
                fclose($readHandle);
                fclose($tmpHandle);
                @unlink($tmpPath);

                return; // keep the uncompacted bundle
            }
        }

        fclose($readHandle);
        fclose($tmpHandle);
        $this->closeBundleHandle(); // Windows cannot rename over an open file

        if (!@rename($tmpPath, $bundlePath)) {
            @unlink($tmpPath);
        }
    }

    /**
     * After gc(), the cache directory holds only the bundle: merged logs, files of older
     * format versions and loose files of pre-bundle releases are removed.
     */
    private function removeUnknownFiles(): void
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
                $this->removeDirectory($item->getPathname());
                continue;
            }

            if ($item->getFilename() !== self::BUNDLE_FILE) {
                @unlink($item->getPathname());
            }
        }
    }

    private function removeDirectory(string $dir): void
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

    private function close(): void
    {
        $this->closeLogHandle();
        $this->closeBundleHandle();
        $this->entries = null;
    }

    private function closeLogHandle(): void
    {
        if ($this->logHandle !== null) {
            fclose($this->logHandle);
            $this->logHandle = null;
            $this->logHandlePid = null;
        }
    }

    private function closeBundleHandle(): void
    {
        if ($this->bundleHandle !== null) {
            fclose($this->bundleHandle);
            $this->bundleHandle = null;
            $this->bundleHandlePid = null;
        }
    }

    private function getBundlePath(): string
    {
        return $this->cacheDir . '/' . self::BUNDLE_FILE;
    }

    /**
     * Real PIDs are never zero, so zero is a safe sentinel when getmypid() is unavailable.
     */
    private function getPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }

    /**
     * @param resource $handle
     */
    private function getFileSize($handle): int
    {
        $stat = fstat($handle);

        return $stat === false ? 0 : $stat['size'];
    }

}

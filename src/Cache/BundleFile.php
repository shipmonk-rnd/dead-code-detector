<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use function clearstatcache;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function getmypid;
use function rename;
use function strlen;
use function unlink;

/**
 * The single data file that holds all merged records. Records are addressed by
 * BundlePosition and carry no framing of their own; the index is the only map.
 */
final class BundleFile
{

    /**
     * @var resource|null
     */
    private $readHandle = null;

    private ?int $readHandlePid = null;

    public function __construct(
        private readonly string $path,
    )
    {
    }

    public function isEmpty(): bool
    {
        clearstatcache(true, $this->path);

        if (!file_exists($this->path)) {
            return true;
        }

        $size = filesize($this->path);

        return $size === false || $size === 0;
    }

    /**
     * Returns null when the bundle is missing or shorter than the index claims,
     * so that the caller can fall back to the loose file.
     */
    public function read(BundlePosition $position): ?string
    {
        $handle = $this->readHandle();

        if ($handle === null) {
            return null;
        }

        if ($position->length === 0) {
            return '';
        }

        fseek($handle, $position->offset);
        $content = fread($handle, $position->length);

        if ($content === false || strlen($content) !== $position->length) {
            return null;
        }

        return $content;
    }

    /**
     * Writes the records to a temporary file and swaps it in atomically. Returns the index of
     * the new bundle, or null when nothing was written and the old bundle stays in place.
     *
     * @param iterable<string, string> $records hash => content, in the order they should be laid out
     */
    public function rewrite(iterable $records): ?BundleIndex
    {
        $tmpPath = $this->path . '.tmp';
        $handle = @fopen($tmpPath, 'wb');

        if ($handle === false) {
            return null;
        }

        $positions = $this->writeRecords($handle, $records, 0);
        fclose($handle);

        if ($positions === null) {
            @unlink($tmpPath);
            return null;
        }

        $this->close();

        if (!@rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            return null;
        }

        return BundleIndex::fromPackedPositions($positions);
    }

    /**
     * Appends the records after the existing ones. Returns the extended index, or null when
     * nothing could be appended.
     *
     * @param iterable<string, string> $records hash => content
     */
    public function append(
        iterable $records,
        BundleIndex $existing,
    ): ?BundleIndex
    {
        clearstatcache(true, $this->path);
        $offset = filesize($this->path);

        if ($offset === false) {
            return null;
        }

        $handle = @fopen($this->path, 'ab');

        if ($handle === false) {
            return null;
        }

        $positions = $this->writeRecords($handle, $records, $offset);
        fclose($handle);

        if ($positions === null || $positions === []) {
            return null;
        }

        return $existing->withAppended(BundleIndex::fromPackedPositions($positions));
    }

    public function close(): void
    {
        if ($this->readHandle !== null) {
            fclose($this->readHandle);
            $this->readHandle = null;
            $this->readHandlePid = null;
        }
    }

    /**
     * @param resource $handle
     * @param iterable<string, string> $records hash => content
     * @return array<string, int>|null hash => packed position, null when a write failed
     */
    private function writeRecords(
        $handle,
        iterable $records,
        int $offset,
    ): ?array
    {
        $positions = [];

        foreach ($records as $hash => $content) {
            $length = strlen($content);

            if ($length > BundlePosition::MAX_LENGTH) {
                continue; // stays a loose file
            }

            if (fwrite($handle, $content) === false) {
                return null;
            }

            $positions[$hash] = (new BundlePosition($offset, $length))->toInt();
            $offset += $length;
        }

        return $positions;
    }

    /**
     * @return resource|null
     */
    private function readHandle()
    {
        $pid = getmypid();

        // a forked child inherits the parent descriptor together with its file
        // offset, so it must open its own instead of seeking in a shared one
        if ($this->readHandle !== null && $pid !== false && $this->readHandlePid === $pid) {
            return $this->readHandle;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return null;
        }

        $this->readHandle = $handle;
        $this->readHandlePid = $pid === false ? null : $pid;

        return $handle;
    }

}

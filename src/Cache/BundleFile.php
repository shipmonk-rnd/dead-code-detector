<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use function fclose;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function getmypid;
use function hrtime;
use function md5;
use function rename;
use function strlen;
use function substr;
use function uniqid;
use function unlink;

/**
 * The single data file that holds all merged records: a 4 byte magic, a 16 byte generation
 * that the index must repeat, then the records back to back. Records carry no framing of
 * their own; the index is the only map.
 */
final class BundleFile
{

    private const MAGIC = 'DCDB';

    private const HEADER_SIZE = 4 + BundleIndex::GENERATION_SIZE;

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

    /**
     * Reads the generation from the file header; this is what ties the file to its index.
     */
    public function getGeneration(): string
    {
        $handle = $this->readHandle();
        fseek($handle, 0);
        $header = fread($handle, self::HEADER_SIZE);

        if ($header === false || strlen($header) !== self::HEADER_SIZE || substr($header, 0, 4) !== self::MAGIC) {
            throw $this->corrupt('unexpected header');
        }

        return substr($header, 4);
    }

    public function read(BundlePosition $position): string
    {
        $handle = $this->readHandle();
        fseek($handle, $position->offset);
        $content = fread($handle, $position->length);

        if ($content === false || strlen($content) !== $position->length) {
            throw $this->corrupt("record at offset {$position->offset} is shorter than the index claims");
        }

        return $content;
    }

    /**
     * Writes a fresh file under a new generation and swaps it in atomically.
     *
     * @param iterable<string, string> $records hash => content, in the order they should be laid out
     */
    public function rewrite(iterable $records): BundleIndex
    {
        $tmpPath = $this->path . '.tmp';
        $handle = fopen($tmpPath, 'wb');

        if ($handle === false) {
            throw new LogicException("Failed to create DCD usage cache bundle '{$tmpPath}'.");
        }

        $generation = md5(uniqid('', true) . hrtime(true), binary: true);
        $this->write($handle, self::MAGIC . $generation);
        $positions = $this->writeRecords($handle, $records, self::HEADER_SIZE);
        fclose($handle);
        $this->close();

        if (!rename($tmpPath, $this->path)) {
            unlink($tmpPath);

            throw new LogicException("Failed to replace DCD usage cache bundle '{$this->path}'.");
        }

        return BundleIndex::fromPackedPositions($generation, $positions);
    }

    /**
     * Appends the records after the existing ones and returns the extended index.
     *
     * @param iterable<string, string> $records hash => content
     */
    public function append(
        iterable $records,
        BundleIndex $existing,
    ): BundleIndex
    {
        $generation = $existing->getGeneration();

        if ($generation === null || $generation !== $this->getGeneration()) {
            throw $this->corrupt('index belongs to a different bundle generation');
        }

        $offset = filesize($this->path);

        if ($offset === false) {
            throw new LogicException("Failed to stat DCD usage cache bundle '{$this->path}'.");
        }

        $handle = fopen($this->path, 'ab');

        if ($handle === false) {
            throw new LogicException("Failed to open DCD usage cache bundle '{$this->path}' for appending.");
        }

        $positions = $this->writeRecords($handle, $records, $offset);
        fclose($handle);

        return $existing->withAppended(BundleIndex::fromPackedPositions($generation, $positions));
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
     * @return array<string, int> hash => packed position; oversized records are left out and stay loose
     */
    private function writeRecords(
        $handle,
        iterable $records,
        int $offset,
    ): array
    {
        $positions = [];

        foreach ($records as $hash => $content) {
            $length = strlen($content);

            if ($length === 0) {
                throw new LogicException("DCD usage cache record '{$hash}' is empty.");
            }

            if ($length > BundlePosition::MAX_LENGTH) {
                continue;
            }

            $this->write($handle, $content);
            $positions[$hash] = (new BundlePosition($offset, $length))->toInt();
            $offset += $length;
        }

        return $positions;
    }

    /**
     * @param resource $handle
     */
    private function write(
        $handle,
        string $bytes,
    ): void
    {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new LogicException("Failed to write DCD usage cache bundle '{$this->path}'.");
        }
    }

    /**
     * @return resource
     */
    private function readHandle()
    {
        $pid = getmypid();

        if ($pid === false) {
            throw new LogicException('Cannot determine the current process id.');
        }

        // a forked child inherits the parent descriptor together with its file
        // offset, so it must open its own instead of seeking in a shared one
        if ($this->readHandle !== null && $this->readHandlePid === $pid) {
            return $this->readHandle;
        }

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new LogicException("Failed to open DCD usage cache bundle '{$this->path}'.");
        }

        $this->readHandle = $handle;
        $this->readHandlePid = $pid;

        return $handle;
    }

    private function corrupt(string $reason): LogicException
    {
        return new LogicException("DCD usage cache bundle '{$this->path}' is corrupt ({$reason}). Clear the PHPStan result cache and re-run the analysis.");
    }

}

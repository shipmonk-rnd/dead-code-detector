<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function intdiv;
use function is_int;
use function pack;
use function str_split;
use function strlen;
use function substr;
use function unpack;
use const LOCK_EX;

/**
 * Maps record hashes to their positions in bundle.dat.
 *
 * On disk: 4 byte magic, 16 byte generation shared with the data file, then all 32 char
 * hashes back to back, then all positions as packed 64bit ints. The two flat blocks decode
 * with one str_split() and one unpack().
 */
final class BundleIndex
{

    private const MAGIC = 'DCD2';

    public const GENERATION_SIZE = 16;

    private const HEADER_SIZE = 4 + self::GENERATION_SIZE;

    private const HASH_SIZE = 32;

    private const POSITION_SIZE = 8;

    /**
     * @param array<string, int> $positions hash => packed position, in bundle order
     */
    private function __construct(
        private readonly ?string $generation,
        private readonly array $positions,
    )
    {
    }

    public static function empty(): self
    {
        return new self(null, []);
    }

    /**
     * @param array<string, int> $positions hash => packed position, in bundle order
     */
    public static function fromPackedPositions(
        string $generation,
        array $positions,
    ): self
    {
        return new self($generation, $positions);
    }

    /**
     * A missing index is the normal state before the first gc(). A foreign magic means another
     * version of this extension wrote the file, so it is ignored and gc() rebuilds the bundle
     * from the loose files. Only a file with our magic and a broken body is corrupt.
     *
     * @throws CorruptUsageCacheException
     */
    public static function load(string $path): self
    {
        if (!file_exists($path)) {
            return self::empty();
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new LogicException("Failed to read DCD usage cache index '{$path}'.");
        }

        if (substr($raw, 0, 4) !== self::MAGIC) {
            return self::empty();
        }

        $body = strlen($raw) - self::HEADER_SIZE;
        $entrySize = self::HASH_SIZE + self::POSITION_SIZE;

        if ($body < 0 || $body % $entrySize !== 0) {
            throw CorruptUsageCacheException::index($path, 'truncated');
        }

        $generation = substr($raw, 4, self::GENERATION_SIZE);
        $entries = intdiv($body, $entrySize);

        if ($entries === 0) {
            return new self($generation, []);
        }

        $hashes = str_split(substr($raw, self::HEADER_SIZE, $entries * self::HASH_SIZE), self::HASH_SIZE);
        $packed = unpack('J*', substr($raw, self::HEADER_SIZE + $entries * self::HASH_SIZE));

        if ($packed === false) {
            throw CorruptUsageCacheException::index($path, 'unreadable positions');
        }

        $positions = [];
        $i = 1; // unpack() numbers its results from one

        foreach ($hashes as $hash) {
            $position = $packed[$i++] ?? null;

            if (!is_int($position)) {
                throw CorruptUsageCacheException::index($path, 'position count does not match hash count');
            }

            $positions[$hash] = $position;
        }

        return new self($generation, $positions);
    }

    public function save(string $path): void
    {
        if ($this->generation === null) {
            throw new LogicException('An empty DCD usage cache index has no generation and cannot be saved.');
        }

        $hashes = '';
        $packed = '';

        foreach ($this->positions as $hash => $position) {
            $hashes .= $hash;
            $packed .= pack('J', $position);
        }

        if (file_put_contents($path, self::MAGIC . $this->generation . $hashes . $packed, LOCK_EX) === false) {
            throw new LogicException("Failed to write DCD usage cache index '{$path}'.");
        }
    }

    public function withAppended(self $other): self
    {
        if ($this->generation !== $other->generation) {
            throw new LogicException('Cannot merge DCD usage cache indexes of different bundle generations.');
        }

        return new self($this->generation, $this->positions + $other->positions);
    }

    public function isEmpty(): bool
    {
        return $this->positions === [];
    }

    public function getGeneration(): ?string
    {
        return $this->generation;
    }

    public function has(string $hash): bool
    {
        return isset($this->positions[$hash]);
    }

    /**
     * @throws CorruptUsageCacheException
     */
    public function get(string $hash): ?BundlePosition
    {
        $position = $this->positions[$hash] ?? null;

        return $position === null ? null : BundlePosition::fromInt($position);
    }

    /**
     * Share of entries that the given run did not read.
     *
     * @param array<string, true> $readHashes
     */
    public function garbageRatio(array $readHashes): float
    {
        if ($this->positions === []) {
            return 0.0;
        }

        $garbage = 0;

        foreach ($this->positions as $hash => $position) {
            if (!isset($readHashes[$hash])) {
                $garbage++;
            }
        }

        return $garbage / count($this->positions);
    }

}

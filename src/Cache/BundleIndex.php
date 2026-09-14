<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use function count;
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
 * On disk: 4 byte magic, then all 32 char hashes back to back, then all positions as
 * packed 64bit ints. Two flat blocks decode with one str_split() and one unpack().
 */
final class BundleIndex
{

    private const MAGIC = 'DCD1';

    private const HASH_SIZE = 32;

    private const POSITION_SIZE = 8;

    /**
     * @param array<string, int> $positions hash => packed position, in bundle order
     */
    private function __construct(
        private readonly array $positions,
    )
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, int> $positions hash => packed position, in bundle order
     */
    public static function fromPackedPositions(array $positions): self
    {
        return new self($positions);
    }

    /**
     * A missing or corrupt index yields an empty one, so every read falls back to loose files.
     */
    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);

        if ($raw === false || substr($raw, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            return self::empty();
        }

        $body = strlen($raw) - strlen(self::MAGIC);
        $entrySize = self::HASH_SIZE + self::POSITION_SIZE;

        if ($body % $entrySize !== 0 || $body === 0) {
            return self::empty();
        }

        $entries = intdiv($body, $entrySize);
        $hashes = str_split(substr($raw, strlen(self::MAGIC), $entries * self::HASH_SIZE), self::HASH_SIZE);
        $packed = unpack('J*', substr($raw, strlen(self::MAGIC) + $entries * self::HASH_SIZE));

        if ($packed === false) {
            return self::empty();
        }

        $positions = [];
        $i = 1; // unpack() numbers its results from one

        foreach ($hashes as $hash) {
            $position = $packed[$i++] ?? null;

            if (!is_int($position)) {
                return self::empty();
            }

            $positions[$hash] = $position;
        }

        return new self($positions);
    }

    public function save(string $path): void
    {
        $hashes = '';
        $packed = '';

        foreach ($this->positions as $hash => $position) {
            $hashes .= $hash;
            $packed .= pack('J', $position);
        }

        @file_put_contents($path, self::MAGIC . $hashes . $packed, LOCK_EX);
    }

    public function withAppended(self $other): self
    {
        return new self($this->positions + $other->positions);
    }

    public function has(string $hash): bool
    {
        return isset($this->positions[$hash]);
    }

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

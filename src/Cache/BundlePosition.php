<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;

/**
 * Where one record lives inside bundle.dat.
 *
 * Offset and length share a single 64bit int on disk so that the whole position block of
 * the index can be decoded by one unpack() call.
 */
final class BundlePosition
{

    private const LENGTH_BITS = 24;

    public const MAX_LENGTH = (1 << self::LENGTH_BITS) - 1;

    /**
     * @param int<1, self::MAX_LENGTH> $length
     */
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
    )
    {
    }

    public static function fromInt(int $packed): self
    {
        $length = $packed & self::MAX_LENGTH;

        if ($length === 0) {
            throw new LogicException('DCD usage cache index holds a zero-length record. Clear the PHPStan result cache and re-run the analysis.');
        }

        return new self($packed >> self::LENGTH_BITS, $length);
    }

    public function toInt(): int
    {
        return ($this->offset << self::LENGTH_BITS) | $this->length;
    }

}

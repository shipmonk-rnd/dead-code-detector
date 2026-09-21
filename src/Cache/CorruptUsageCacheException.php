<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;

/**
 * On-disk state that normal operation cannot produce. UsageCacheStorage moves the
 * offending files aside before it propagates the failure.
 */
final class CorruptUsageCacheException extends LogicException
{

    public static function index(
        string $path,
        string $reason,
    ): self
    {
        return new self("DCD usage cache index '{$path}' is corrupt ({$reason}).");
    }

    public static function bundle(
        string $path,
        string $reason,
    ): self
    {
        return new self("DCD usage cache bundle '{$path}' is corrupt ({$reason}).");
    }

}

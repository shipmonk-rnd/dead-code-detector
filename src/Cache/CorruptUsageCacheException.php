<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Cache;

use LogicException;

/**
 * On-disk state that normal operation cannot produce. UsageCacheStorage moves the
 * offending files aside before it propagates the failure.
 */
final class CorruptUsageCacheException extends LogicException
{

}

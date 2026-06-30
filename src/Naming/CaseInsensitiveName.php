<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Naming;

use function array_map;
use function in_array;
use function str_ends_with;
use function str_starts_with;
use function strcasecmp;
use function strtolower;

/**
 * Helpers for matching method and function names, which PHP compares case-insensitively.
 *
 * Providers detect magic members by name (e.g. `getMethod`, `handleXxx`, lifecycle events). The actual
 * call or declaration may use a different case than the literal we compare against, yet PHP would still
 * dispatch to the same method/function - so all such comparisons must ignore case to match runtime.
 *
 * Only use this for method/function names. Constant names, enum case names and (config/route/service)
 * strings are case-sensitive and must keep using plain comparisons.
 */
final class CaseInsensitiveName
{

    public static function equals(
        string $name,
        string $other,
    ): bool
    {
        return strcasecmp($name, $other) === 0;
    }

    public static function startsWith(
        string $name,
        string $prefix,
    ): bool
    {
        return str_starts_with(strtolower($name), strtolower($prefix));
    }

    public static function endsWith(
        string $name,
        string $suffix,
    ): bool
    {
        return str_ends_with(strtolower($name), strtolower($suffix));
    }

    /**
     * @param list<string> $names
     */
    public static function isOneOf(
        string $name,
        array $names,
    ): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $names), true);
    }

}

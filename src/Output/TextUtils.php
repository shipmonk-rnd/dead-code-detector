<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Output;

use function in_array;
use function strlen;
use function substr;

final class TextUtils
{

    public static function pluralize(
        int $count,
        string $singular
    ): string
    {
        if ($count === 1) {
            return $singular;
        }

        if (substr($singular, -1) === 's' || substr($singular, -1) === 'x' || substr($singular, -2) === 'sh' || substr($singular, -2) === 'ch') {
            return $singular . 'es';
        }

        if (substr($singular, -1) === 'y' && !in_array($singular[strlen($singular) - 2], ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($singular, 0, -1) . 'ies';
        }

        return $singular . 's';
    }

}

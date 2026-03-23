<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Output;

use function in_array;
use function str_ends_with;
use function strlen;
use function substr;

final class TextUtils
{

    public static function pluralize(
        int $count,
        string $singular,
    ): string
    {
        if ($count === 1) {
            return $singular;
        }

        if (str_ends_with($singular, 's') || str_ends_with($singular, 'x') || str_ends_with($singular, 'sh') || str_ends_with($singular, 'ch')) {
            return $singular . 'es';
        }

        if (str_ends_with($singular, 'y') && !in_array($singular[strlen($singular) - 2], ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($singular, 0, -1) . 'ies';
        }

        return $singular . 's';
    }

}

<?php declare(strict_types = 1);

namespace DeadConstPhpDoc;

use DeadConstPhpDoc\Limits as AliasedLimits;

final class PaginationInput
{

    public const MAX_PAGE_SIZE = 100;
    public const UNUSED = 1; // error: Unused DeadConstPhpDoc\PaginationInput::UNUSED

    /**
     * @param int<1, self::MAX_PAGE_SIZE> $pageSize
     */
    public static function create(int $pageSize): void
    {
        echo $pageSize;
    }

}

final class Limits
{

    public const MAX_ITEMS = 1000;
    public const MIN_ITEMS = 1;
    public const VIA_ALIAS = 5;

}

final class BulkInput
{

    /**
     * @param int<0, Limits::MAX_ITEMS> $count
     * @param array<Limits::MIN_ITEMS, string> $names
     * @return AliasedLimits::VIA_ALIAS|null
     */
    public static function process(int $count, array $names): ?int
    {
        echo $count;
        echo count($names);

        return null;
    }

}

abstract class Base
{

    public const BASE_MAX = 50;

}

final class Child extends Base
{

    /**
     * @param int<1, parent::BASE_MAX> $value
     */
    public static function handle(int $value): void
    {
        echo $value;
    }

}

final class Sizes
{

    public const SIZE_SMALL = 1; // error: Unused DeadConstPhpDoc\Sizes::SIZE_SMALL
    public const SIZE_LARGE = 2; // error: Unused DeadConstPhpDoc\Sizes::SIZE_LARGE

    /**
     * @param self::SIZE_* $size
     */
    public static function pick(int $size): void
    {
        echo $size;
    }

}

PaginationInput::create(5);
BulkInput::process(1, []);
Child::handle(1);
Sizes::pick(1);

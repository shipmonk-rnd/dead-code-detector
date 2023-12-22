<?php declare(strict_types = 1);

namespace DeadEntrypoint;

class Entrypoint
{

    public function __construct()
    {
    }

    public function someUnused(): void
    {
        Dead::usedMethod();
    }
}

class Dead
{

    public static function unusedMethod(): void {} // error: Unused DeadEntrypoint\Dead::unusedMethod
    public static function usedMethod(): void {}

}

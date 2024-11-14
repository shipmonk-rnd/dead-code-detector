<?php declare(strict_types = 1);

namespace Anonym;

class Other
{
    public static function bar(): void
    {
    }
}

new class {
    public function __construct()
    {
        Other::bar();
    }
};

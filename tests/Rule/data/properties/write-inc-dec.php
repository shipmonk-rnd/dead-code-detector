<?php declare(strict_types=1);

namespace PropertyWriteIncDec;

class Test
{
    public static int $count; // error: Unused PropertyWriteIncDec\Test::count
}

function test(): void {
    Test::$count++;
}

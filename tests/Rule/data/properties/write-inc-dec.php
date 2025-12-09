<?php declare(strict_types=1);

namespace PropertyWriteIncDec;

class Test
{
    public static int $count1; // strictly speaking unused, but detection of unused result of ++ not yet implemented
    public static int $count2;
    public static int $count3; // error: Unused PropertyWriteIncDec\Test::count3
}

function test(): void {
    Test::$count1++;
    echo Test::$count2--;
}

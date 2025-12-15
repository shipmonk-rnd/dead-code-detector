<?php declare(strict_types=1);

namespace PropertyWriteIncDec;

class Test
{
    public static int $count0; // error: Unused PropertyWriteIncDec\Test::count0
    public static int $count1; // error: Unused PropertyWriteIncDec\Test::count1
    public static int $count2;
    public static int $count3;
    public static int $count4;
    public static int $count5;
    public static int $count6;
    public static int $count7;
    public static int $count8;
    public static int $count9; // error: Unused PropertyWriteIncDec\Test::count9
}

function test() {
    // write only
    --Test::$count0;
    Test::$count1++;

    // read & write
    echo Test::$count2--;
    str_repeat('', Test::$count3++);
    match (true) {
        Test::$count4-- => '',
        default => ''
    };

    yield --Test::$count5;

    echo ++Test::$count6 ? 'a' : 'b';
    echo true ? Test::$count7++ : 'b';

    return Test::$count8++;
}

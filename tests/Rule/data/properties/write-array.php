<?php declare(strict_types=1);

namespace PropertyWriteArray;

class Test
{
    public array $array; // error: Unused PropertyWriteArray\Test::array
    public array $array2; // error: Unused PropertyWriteArray\Test::array2

}

function test(): void {
    $test = new Test();
    $test->array['name'] = 'John';
    $test->array2['more']['name'] = 'John';
}

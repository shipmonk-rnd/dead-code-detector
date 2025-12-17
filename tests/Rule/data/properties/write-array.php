<?php declare(strict_types=1);

namespace PropertyWriteArray;

class Test
{
    public array $array; // error: Property PropertyWriteArray\Test::array is never read
    public array $array2; // error: Property PropertyWriteArray\Test::array2 is never read

}

function test(): void {
    $test = new Test();
    $test->array['name'] = 'John';
    $test->array2['more']['name'] = 'John';
}

<?php declare(strict_types=1);

namespace PropertyArrayWrite;

class ArrayDimTest
{
    public array $array; // error: Unused PropertyArrayWrite\ArrayDimTest::array
    public array $array2; // error: Unused PropertyArrayWrite\ArrayDimTest::array2
    public array $array3; // error: Unused PropertyArrayWrite\ArrayDimTest::array3

}

function test(): void {
    $test = new ArrayDimTest();
    $test->array['name'] = 'John';
    $test->array2['more']['name'] = 'John';
}

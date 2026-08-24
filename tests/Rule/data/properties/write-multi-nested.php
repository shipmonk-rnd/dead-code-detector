<?php declare(strict_types=1);

namespace PropertyMultiWriteNested;

class Person
{
    public string $flat; // error: Property PropertyMultiWriteNested\Person::$flat is never read
    public string $nested; // error: Property PropertyMultiWriteNested\Person::$nested is never read
    public string $keyed; // error: Property PropertyMultiWriteNested\Person::$keyed is never read
    public string $legacy; // error: Property PropertyMultiWriteNested\Person::$legacy is never read
}

function test(Person $p) {
    [$p->flat] = ['a'];
    [[$p->nested]] = [['b']];
    ['k' => ['j' => $p->keyed]] = ['k' => ['j' => 'c']];
    list(list($p->legacy)) = [['d']];
}

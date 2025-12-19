<?php declare(strict_types=1);

namespace PropertyWriteCoalesce;

class Coalesce
{
    public $prop1; // error: Property PropertyWriteCoalesce\Coalesce::prop1 is never read
    public $prop2;
}

function test(Coalesce $c) {
    $c->prop1 ??= 1;
    return $c->prop2 ??= 2;
}

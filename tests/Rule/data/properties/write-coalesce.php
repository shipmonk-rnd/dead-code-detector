<?php declare(strict_types=1);

namespace PropertyWriteCoalesce;

class Coalesce
{
    public $prop; // error: Property PropertyWriteCoalesce\Coalesce::prop is never read
}

function test(Coalesce $c): void {
    $c->prop ??= 1;
}

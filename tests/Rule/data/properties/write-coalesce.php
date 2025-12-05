<?php declare(strict_types=1);

namespace PropertyWriteCoalesce;

class Coalesce
{
    public $prop; // error: Unused PropertyWriteCoalesce\Coalesce::prop
}

function test(Coalesce $c): void {
    $c->prop ??= 1;
}

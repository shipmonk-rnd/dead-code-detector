<?php declare(strict_types = 1);

namespace Filtering;

class Example // @phpstan-ignore invalid.ignore
{

    public const FOO1 = 1; // @phpstan-ignore shipmonk.deadConstant
    public const FOO2 = 2;

    public function used(): void
    {
        $this->dead();
    }

    public function dead(): void // @phpstan-ignore shipmonk.deadMethod (is filtered out)
    {
    }

}

(new Example())->used();

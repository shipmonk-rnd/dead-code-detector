<?php declare(strict_types = 1);

namespace CtorPrivate;

class StaticCtor
{
    private function __construct()
    {
        $this->transitive();
    }

    public static function create(): self
    {
        return new self();
    }

    private function transitive()
    {
    }
}

StaticCtor::create();

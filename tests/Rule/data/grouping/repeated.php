<?php declare(strict_types = 1);

namespace GroupingRepeated;

class Incriminated
{
    public function baz(): void {}
}

class User
{
    public function __construct(Incriminated $incriminated)
    {
        $incriminated->baz();
        $incriminated->baz();
    }

}


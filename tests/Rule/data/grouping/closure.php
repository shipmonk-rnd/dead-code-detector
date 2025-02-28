<?php declare(strict_types = 1);

namespace GroupingClosure;

class Incriminated
{
    public function baz(): void {}
}

class ClosureUser
{
    public function __construct()
    {
        function (Incriminated $incriminator) {
            $incriminator->baz();
        };
    }

}


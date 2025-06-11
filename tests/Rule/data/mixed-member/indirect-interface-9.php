<?php declare(strict_types = 1);

namespace MixedMemberIndirect9;

interface Root
{
    public function getFoo(): string;
}

interface Intermediate extends Root
{

}

class Baz implements Intermediate
{
    public function getFoo(): string
    {
        return 'Foo';
    }

}

function foobar(Intermediate $intermediate, string $method): void
{
    echo $intermediate->$method();
}

<?php declare(strict_types=1);

namespace PropertyMultiWrite;

class Person
{
    public string $first; // error: Unused PropertyMultiWrite\Person::first
    public string $last; // error: Unused PropertyMultiWrite\Person::last

    public function __construct(string $name) {
        [$this->first, $this->last] = explode(' ', $name, 2);
    }
}

function test(): void {
    new Person('John Doe');
}

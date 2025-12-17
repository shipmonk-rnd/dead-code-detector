<?php declare(strict_types=1);

namespace PropertyMultiWrite;

class Person
{
    public string $first; // error: Property PropertyMultiWrite\Person::first is never read
    public string $last; // error: Property PropertyMultiWrite\Person::last is never read

    public function __construct(string $name) {
        [$this->first, $this->last] = explode(' ', $name, 2);
    }
}

function test(): void {
    new Person('John Doe');
}

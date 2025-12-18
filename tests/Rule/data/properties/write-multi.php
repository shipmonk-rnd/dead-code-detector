<?php declare(strict_types=1);

namespace PropertyMultiWrite;

class Person
{
    public string $first; // error: Property PropertyMultiWrite\Person::first is never read
    public string $last; // error: Property PropertyMultiWrite\Person::last is never read
    public string $city;
    public string $zip;
    public string $country;
}

function test(Person $p) {
    [$p->first, $p->last] = ['John', 'Doe'];
    return [$p->city, $p->zip, $p->country] = ['Prague', '10300', 'CZ'];
}

<?php

declare(strict_types=1);

namespace PropertyHooks1;

class Person
{

    public string $first;

    public string $last;

    public string $fullName {
        get {
            return "$this->first $this->last";
        }
        set(string $value) {
            [$this->first, $this->last] = explode(' ', $value, 2);
        }
    }

    public string $city = 'default value' { // error: Property PropertyHooks1\Person::city is never read
        get => $this->city;

        set {
            $this->city = strtolower($value);
        }
    }
}

function test(Person $person) {
    echo $person->fullName;
}

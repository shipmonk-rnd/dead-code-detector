<?php

declare(strict_types=1);

namespace PropertyHooks1;

class Person
{

    public string $first; // error: Property PropertyHooks1\Person::$first is never written

    public string $last; // error: Property PropertyHooks1\Person::$last is never written

    public string $fullName { // error: Property PropertyHooks1\Person::$fullName is never written
        get {
            return "$this->first $this->last";
        }
        set(string $value) {
            [$this->first, $this->last] = explode(' ', $value, 2);
        }
    }

    public string $city = 'default value' { // error: Property PropertyHooks1\Person::$city is never read
        get => $this->city;

        set {
            $this->city = strtolower($value);
        }
    }
}

function test(Person $person) {
    echo $person->fullName;
}

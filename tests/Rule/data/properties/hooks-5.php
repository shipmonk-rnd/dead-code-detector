<?php declare(strict_types=1);

namespace PropertyHooks5;

class Person
{

    public string $usedInSetHook;
    public string $usedInGetHook; // error: Unused PropertyHooks5\Person::usedInGetHook

    public string $hooked { // error: Unused PropertyHooks5\Person::hooked
        get {
            return $this->usedInGetHook;
        }
        set(string $value) {
            $this->hooked = $this->usedInSetHook;
        }
    }
}

function test(Person $person) {
    $person->hooked = 'value';
}

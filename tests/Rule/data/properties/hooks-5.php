<?php declare(strict_types=1);

namespace PropertyHooks5;

class Person
{

    public string $usedInSetHook;
    public string $usedInGetHook; // error: Property PropertyHooks5\Person::$usedInGetHook is never read

    public string $hooked { // error: Property PropertyHooks5\Person::$hooked is never read
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

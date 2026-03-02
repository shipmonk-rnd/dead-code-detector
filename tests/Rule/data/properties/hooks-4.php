<?php declare(strict_types=1);

namespace PropertyHooks4;

class Person
{

    public string $usedInSetHook; // error: Property PropertyHooks4\Person::$usedInSetHook is never read // error: Property PropertyHooks4\Person::$usedInSetHook is never written
    public string $usedInGetHook; // error: Property PropertyHooks4\Person::$usedInGetHook is never written

    public string $hooked { // error: Property PropertyHooks4\Person::$hooked is never written
        get {
            return $this->usedInGetHook;
        }
        set(string $value) {
            $this->hooked = $this->usedInSetHook;
        }
    }
}

function test(Person $person) {
    echo $person->hooked;
}

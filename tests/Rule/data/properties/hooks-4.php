<?php declare(strict_types=1);

namespace PropertyHooks4;

class Person
{

    public string $usedInSetHook; // error: Property PropertyHooks4\Person::$usedInSetHook is never read
    public string $usedInGetHook;

    public string $hooked {
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

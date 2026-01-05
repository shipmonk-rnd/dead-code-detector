<?php declare(strict_types=1);

namespace PropertyHooks3;

interface Named {
    public string $name { get; } // error: Property PropertyHooks3\Named::$name is never read
}

class User implements Named {

    public function __construct( // error: Unused PropertyHooks3\User::__construct
        public string $name = 'default'
    ) {}
}

function foo(User $user): void {
    echo $user->name;
}

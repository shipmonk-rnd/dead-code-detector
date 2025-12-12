<?php declare(strict_types=1);

namespace PropertyHooks3;

interface Named {
    public string $name { get; } // error: Unused PropertyHooks3\Named::name
}

class User implements Named {

    public function __construct( // error: Unused PropertyHooks3\User::__construct
        public string $name = 'default'
    ) {}
}

function foo(User $user): void {
    echo $user->name;
}

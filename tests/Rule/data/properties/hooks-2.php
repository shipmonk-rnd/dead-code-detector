<?php declare(strict_types=1);

namespace PropertyHooks2;

interface Named {
    public string $name { get; }
}

class User implements Named {
    public function __construct( // error: Unused PropertyHooks2\User::__construct
        public string $name
    ) {}
}

function foo(Named $named): void {
    $named->name;
}

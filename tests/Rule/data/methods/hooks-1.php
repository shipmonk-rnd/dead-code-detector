<?php

declare(strict_types=1);

namespace Hooks1;

class State
{
    public static function call1() {}
    public static function call2() {} // error: Unused Hooks1\State::call2
}

final class Test
{
    public State $prop1 {
        get => State::call1();
    }

    public State $prop2 { // error: Property Hooks1\Test::prop2 is never read
        get => State::call2();
    }
}

function test(): mixed {
    return new Test()->prop1;
}

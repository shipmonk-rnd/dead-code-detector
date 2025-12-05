<?php

declare(strict_types=1);

namespace Hooks1;

class State
{
    public static function empty(): self
    {
        return new self();
    }
}

final class Test
{
    public State $state { // error: Unused Hooks1\Test::state
        get => State::empty(); // hooks are considered always called, thus currently not part of the transitivity chain
    }
}

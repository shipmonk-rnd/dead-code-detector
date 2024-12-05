<?php declare(strict_types = 1);

namespace NewInInitializers;

class State {
    public function __construct() // currently, this is not detected as transitively unused, but it should be
    {
    }
}

class MyStateMachine
{
    public function __construct( // error: Unused NewInInitializers\MyStateMachine::__construct
        State $state = new State(),
    ) {
    }
}

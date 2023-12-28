<?php declare(strict_types = 1);

namespace DeadFirstClassCallable;

class A {

    public function unused(): void // error: Unused DeadFirstClassCallable\A::unused
    {
        $callback = $this->usedByFirstClassCallable(...);
    }

    public function usedByFirstClassCallable(): void
    {

    }
}

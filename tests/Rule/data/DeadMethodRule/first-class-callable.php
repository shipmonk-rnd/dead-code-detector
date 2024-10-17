<?php declare(strict_types = 1);

namespace DeadFirstClassCallable;

class A {

    public function used(): void
    {
        $callback = $this->usedByFirstClassCallable(...);
    }

    public function usedByFirstClassCallable(): void
    {

    }
}

(new A())->used();

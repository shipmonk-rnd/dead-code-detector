<?php declare(strict_types = 1);

namespace DeadFirstClassCallable;

class A {

    #[MyAttribute(self::usedByFirstClassCallableInAttribute(...))] // since PHP 8.5
    public function used(): void
    {
        $callback = $this->usedByFirstClassCallable(...);
    }

    public function usedByFirstClassCallable(): void {}
    public static function usedByFirstClassCallableInAttribute(): void {}
}

(new A())->used();

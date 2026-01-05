<?php declare(strict_types = 1);

namespace DeadPropertyPromotedHook;

class Example
{
    public function __construct(
        public string $foo { // error: Property DeadPropertyPromotedHook\Example::$foo is never read
            set (string $value) {
                self::called();
                $this->foo = $value;
            }
        },
    ) {
    }

    public static function called() {
    }
}

function test() {
    new Example('foo');
}

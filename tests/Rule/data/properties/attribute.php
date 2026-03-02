<?php declare(strict_types = 1);

namespace DeadPropertyAttribute;

use Attribute;

#[Attribute]
class MyAttribute {

    public function __construct(
        public string $name, // error: Property DeadPropertyAttribute\MyAttribute::$name is never read
        public int $priority, // error: Property DeadPropertyAttribute\MyAttribute::$priority is never read
    ) {
    }
}

#[MyAttribute('test', 1)]
class UserClass
{
}


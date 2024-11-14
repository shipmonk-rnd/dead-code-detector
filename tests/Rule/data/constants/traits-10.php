<?php declare(strict_types = 1);

namespace DeadTraitConst10;

trait StaticExample {
    const DO = 1;
}

class Example {
    use StaticExample;
}

echo Example::DO;

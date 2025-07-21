<?php declare(strict_types = 1);

namespace CallSoftFinal;

/**
 * @final
 */
class SoftFinalParent
{
    public function __construct() {
        $this->used();
    }

    public function used(): void {}
}

class Child extends SoftFinalParent
{
    public function used(): void {}
}


new Child();


<?php declare(strict_types = 1);

namespace DeadParent;

class A
{

    public function __construct()
    {
    }

    public function someUnused(): void { // error: Unused DeadParent\A::someUnused

    }
}

class B extends A
{

    /**
     * @inheritDoc
     */
    public function __construct()
    {
        parent::__construct();
    }

}

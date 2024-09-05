<?php

namespace OverrideAttribute;

interface FooInterface
{

    public function doSomething(): void; // error: Unused OverrideAttribute\FooInterface::doSomething

}

class Foo implements FooInterface
{

    #[\Override]
    public function doSomething(): void {}

}

abstract class AbstractBar
{

    abstract public function doSomething(): void; // error: Unused OverrideAttribute\AbstractBar::doSomething

}

class Bar extends AbstractBar
{

    #[\Override]
    public function doSomething(): void {}

}

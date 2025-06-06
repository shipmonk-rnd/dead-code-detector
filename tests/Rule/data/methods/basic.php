<?php declare(strict_types = 1);

namespace DeadBasic;

interface TestInterface {
    public function differentMethod(): void;
    public function interfaceMethod(): void;
}

class TestA {

    public function commonMethod(): void
    {

    }
}

class TestB implements TestInterface {
    public function commonMethod(): void
    {

    }

    public function differentMethod(): void
    {

    }

    public function interfaceMethod(): void
    {

    }
}

trait TestTrait {

    public function __construct() // error: Unused DeadBasic\TestTrait::__construct
    {
    }

    public function traitMethodUsed(): void
    {

    }

    public function traitMethodUnused(): void // error: Unused DeadBasic\TestTrait::traitMethodUnused
    {

    }
}

abstract class TestParent {

    use TestTrait;

    public function __construct() // error: Unused DeadBasic\TestParent::__construct
    {
    }

    public function parentMethodUsed(TestChild $child): void
    {
        $child->childMethodUsed();
        $this->traitMethodUsed();
    }

    public function overwrittenParentMethodUsedByChild(): void // error: Unused DeadBasic\TestParent::overwrittenParentMethodUsedByChild
    {

    }

    public function parentMethodUnused(): void  // error: Unused DeadBasic\TestParent::parentMethodUnused
    {

    }
}

final class TestChild extends TestParent {

    public function __construct(TestA|TestB $class)
    {
        $class->commonMethod();
        $class->differentMethod();
        $this->overwrittenParentMethodUsedByChild();
        $this->childMethodNowUsed();
    }

    public function childMethodNowUsed(TestInterface|TestA $class, TestInterface $interface): void
    {
        $class->differentMethod();
        $interface->interfaceMethod();
    }

    public function childMethodUnused(): void // error: Unused DeadBasic\TestChild::childMethodUnused
    {

    }

    public function childMethodUsed(): void
    {

    }

    public function overwrittenParentMethodUsedByChild(): void
    {
        $this->parentMethodUsed($this);
    }
}

function test() {
    new TestChild();
}

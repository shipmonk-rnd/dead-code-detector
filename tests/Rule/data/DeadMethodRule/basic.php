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

final class TestChild extends TestParent {

    public function __construct(TestA|TestB $class)
    {
        $class->commonMethod();
        $class->differentMethod();
        $this->overwrittenParentMethodUsedByChild();
    }

    public function childMethodUnused(TestInterface|TestA $class, TestInterface $interface): void // error: Unused DeadBasic\TestChild::childMethodUnused
    {
        $class->differentMethod(); // TODO this is unused since the caller is unused
        $interface->interfaceMethod();
    }

    public function childMethodUsed(): void
    {
        $this->parentMethodUsed($this);
    }

    public function overwrittenParentMethodUsedByChild(): void
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

class StaticClass {

    private function __construct()
    {
    }
}

new TestChild();

<?php declare(strict_types = 1);

namespace DeadPropertyTraits;

trait TestTrait {

    public string $traitUsedProperty;

    public string $traitUnusedProperty; // error: Property DeadPropertyTraits\TestTrait::$traitUnusedProperty is never read

    public function useTraitProperty(): void
    {
        $this->traitUsedProperty = 'trait value';
        echo $this->traitUsedProperty;
    }
}

class TestClass {

    use TestTrait;

    public string $classUsedProperty;

    public string $classUnusedProperty; // error: Property DeadPropertyTraits\TestClass::$classUnusedProperty is never read

    public function __construct()
    {
        $this->classUsedProperty = 'class value';
        $this->useTraitProperty();
    }
}

function test() {
    $obj = new TestClass();
    echo $obj->classUsedProperty;
    echo $obj->traitUsedProperty;
}

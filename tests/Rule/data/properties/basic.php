<?php declare(strict_types = 1);

namespace DeadPropertyBasic;

class TestClass {

    public string $usedPublicProperty;
    public string $usedPublicPropertyByChild;
    public string $unusedPublicProperty; // error: Property DeadPropertyBasic\TestClass::unusedPublicProperty is never read
    public string $readNotWritten = 'default';

    public function __construct()
    {
        $this->usedPublicProperty = 'used';
        $this->unusedPublicProperty = 'assigned but unused';
        echo $this->readNotWritten;
    }

    public function useProperty(): void
    {
        echo $this->usedPublicProperty;
    }
}

class ChildClass extends TestClass {

    public string $childUsedProperty;

    public function useChildProperty(): void
    {
        echo $this->childUsedProperty;
        echo $this->usedPublicPropertyByChild;
    }
}

function test() {
    $obj = new TestClass();
    echo $obj->usedPublicProperty;
    $obj->useProperty();

    $child = new ChildClass();
    $child->useChildProperty();
}

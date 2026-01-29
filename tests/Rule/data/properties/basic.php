<?php declare(strict_types = 1);

namespace DeadPropertyBasic;

class TestClass {

    public string $usedPublicProperty;
    public string $usedPublicPropertyByChild; // error: Property DeadPropertyBasic\TestClass::$usedPublicPropertyByChild is never written
    public string $unusedPublicProperty; // error: Property DeadPropertyBasic\TestClass::$unusedPublicProperty is never read
    public string $readNotWritten = 'default'; // error: Property DeadPropertyBasic\TestClass::$readNotWritten is never written

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

    public string $childUsedProperty; // error: Property DeadPropertyBasic\ChildClass::$childUsedProperty is never written

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

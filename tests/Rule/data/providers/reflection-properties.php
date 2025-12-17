<?php declare(strict_types = 1);

namespace ReflectionProperties;

class Holder1
{
    public string $used;
    public string $notUsed; // error: Property ReflectionProperties\Holder1::notUsed is never read
}

class Holder2
{
    public string $property1;
    public string $property2; // error: Property ReflectionProperties\Holder2::property2 is never read
    public string $property3;
}

class Holder3
{
    public string $property1;
    public string $property2;
    public string $property3;
}

class Holder4
{
    public string $unused; // error: Property ReflectionProperties\Holder4::unused is never read
}

class Holder5
{
    public string $property;
}

abstract class HolderParent {}
class Holder6 extends HolderParent
{
    public string $notInParent;
}

class TransitiveHolder {
    public string $transitivelyDead; // error: Property ReflectionProperties\TransitiveHolder::transitivelyDead is never read

    public function test() // error: Unused ReflectionProperties\TransitiveHolder::test
    {
        (new \ReflectionClass(self::class))->getProperty('transitivelyDead');
    }
}

abstract class GetAllPropertiesParent {
    public static function getProperties()
    {
        return (new \ReflectionClass(static::class))->getProperties();
    }

    /**
     * @param \ReflectionClass<self> $reflection
     */
    public static function getProperties2(\ReflectionClass $reflection)
    {
        return $reflection->getProperties();
    }
}

class GetAllPropertiesChild extends GetAllPropertiesParent {
    public string $property;
}

function test() {
    GetAllPropertiesChild::getProperties();
    GetAllPropertiesChild::getProperties2();

    $reflection1 = new \ReflectionClass(Holder1::class);
    $reflection1->getProperty('used');

    $reflection2 = new \ReflectionClass(Holder2::class);
    $reflection2->getProperty('property1');
    $reflection2->getProperty('property3');

    $reflection3 = new \ReflectionClass(Holder3::class);
    $reflection3->getProperties();

    $reflection5 = new \ReflectionClass(Holder5::class);
    $reflection5->getProperty('property');
}

/**
 * @param class-string<HolderParent> $fqn
 */
function testPropertyOnlyInDescendant(string $fqn) {
    $classReflection = new \ReflectionClass($fqn);

    if ($classReflection->hasProperty('notInParent')) {
        $classReflection->getProperty('notInParent');
    }
}

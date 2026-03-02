<?php declare(strict_types = 1);

namespace DeadPropertyMixed2;


class Clazz {

    public string $prop1; // error: Property DeadPropertyMixed2\Clazz::$prop1 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Clazz::$prop1 is never written
    public string $prop2; // error: Property DeadPropertyMixed2\Clazz::$prop2 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Clazz::$prop2 is never written
    public string $prop3; // error: Property DeadPropertyMixed2\Clazz::$prop3 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Clazz::$prop3 is never written
    public string $prop4; // error: Property DeadPropertyMixed2\Clazz::$prop4 is never read // error: Property DeadPropertyMixed2\Clazz::$prop4 is never written
    public string $prop5; // error: Property DeadPropertyMixed2\Clazz::$prop5 is never written
    public string $prop6; // error: Property DeadPropertyMixed2\Clazz::$prop6 is never read // error: Property DeadPropertyMixed2\Clazz::$prop6 is never written

    public string $someProperty; // error: Property DeadPropertyMixed2\Clazz::$someProperty is never read // error: Property DeadPropertyMixed2\Clazz::$someProperty is never written

}

interface IFace {

}

class Implementor implements IFace {

    public string $prop1; // error: Property DeadPropertyMixed2\Implementor::$prop1 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Implementor::$prop1 is never written
    public string $prop2; // error: Property DeadPropertyMixed2\Implementor::$prop2 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Implementor::$prop2 is never written
    public string $prop3; // error: Property DeadPropertyMixed2\Implementor::$prop3 is never read (all usages excluded by usageOverMixed excluder) // error: Property DeadPropertyMixed2\Implementor::$prop3 is never written
    public string $prop4; // error: Property DeadPropertyMixed2\Implementor::$prop4 is never written
    public string $prop5; // error: Property DeadPropertyMixed2\Implementor::$prop5 is never read // error: Property DeadPropertyMixed2\Implementor::$prop5 is never written
    public string $prop6; // error: Property DeadPropertyMixed2\Implementor::$prop6 is never written

}

class Tester
{
    function __construct($mixed, string $notClass, object $object, IFace $iface, int|Clazz $maybeClass)
    {
        echo $mixed->prop1; // may be valid

        if (!$mixed instanceof Clazz) {
            echo $mixed->prop2; // ideally, should mark only IFace, not Clazz (not implemented)
        }

        echo $object->prop3;
        echo $iface->prop4;
        echo $maybeClass->prop5; // mark only Clazz, not IFace

        $this->testPropertyExists();
    }

    function testPropertyExists(Iface $iface)
    {
        echo $iface->someProperty; // does not not mark Clazz
        echo $iface->prop6; // not defined on Iface, but should mark used on its implementations but not on unrelated Clazz
    }
}

new Tester();

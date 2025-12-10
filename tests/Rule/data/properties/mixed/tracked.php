<?php declare(strict_types = 1);

namespace DeadPropertyMixed;


class Clazz {

    public string $prop1;
    public string $prop2;
    public string $prop3;
    public string $prop4; // error: Unused DeadPropertyMixed\Clazz::prop4
    public string $prop5;
    public string $prop6; // error: Unused DeadPropertyMixed\Clazz::prop6

    public string $someProperty; // error: Unused DeadPropertyMixed\Clazz::someProperty

}

interface IFace {

}

class Implementor implements IFace {

    public string $prop1;
    public string $prop2;
    public string $prop3;
    public string $prop4;
    public string $prop5; // error: Unused DeadPropertyMixed\Implementor::prop5
    public string $prop6;

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

function test() {
    new Tester();
}

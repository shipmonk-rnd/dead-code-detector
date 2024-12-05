<?php declare(strict_types = 1);

namespace DeadConstMixed2;


class Clazz {

    const CONST1 = 1; // error: Unused DeadConstMixed2\Clazz::CONST1
    const CONST2 = 1; // error: Unused DeadConstMixed2\Clazz::CONST2
    const CONST3 = 1; // error: Unused DeadConstMixed2\Clazz::CONST3
    const CONST4 = 1; // error: Unused DeadConstMixed2\Clazz::CONST4
    const CONST5 = 1;
    const CONST6 = 1; // error: Unused DeadConstMixed2\Clazz::CONST6

    const SOME_CONST = 1; // error: Unused DeadConstMixed2\Clazz::SOME_CONST

}

interface IFace {

    const CONST1 = 1; // error: Unused DeadConstMixed2\IFace::CONST1
    const CONST2 = 1; // error: Unused DeadConstMixed2\IFace::CONST2
    const CONST3 = 1; // error: Unused DeadConstMixed2\IFace::CONST3
    const CONST4 = 1;
    const CONST5 = 1; // error: Unused DeadConstMixed2\IFace::CONST5

}

class Implementor implements IFace {

    const CONST1 = 1; // error: Unused DeadConstMixed2\Implementor::CONST1
    const CONST2 = 1; // error: Unused DeadConstMixed2\Implementor::CONST2
    const CONST3 = 1; // error: Unused DeadConstMixed2\Implementor::CONST3
    const CONST4 = 1;
    const CONST5 = 1; // error: Unused DeadConstMixed2\Implementor::CONST5
    const CONST6 = 1;

}

class Tester
{
    function __construct($mixed, string $notClass, object $object, IFace $iface, int|Clazz $maybeClass)
    {
        echo $mixed::CONST1; // may be valid

        if (!$mixed instanceof Clazz) {
            echo $mixed::CONST2; // ideally, should mark only IFace, not Clazz (not implemented)
        }

        echo $object::CONST3;
        echo $iface::CONST4;
        echo $maybeClass::CONST5; // mark only Clazz, not IFace

        $this->testMethodExists();
    }

    function testMethodExists(Iface $iface)
    {
        echo $iface::SOME_CONST; // does not not mark Clazz
        echo $iface::CONST6; // not defined on Iface, but should mark used on its implementations but not on unrelated Clazz
    }
}

new Tester();

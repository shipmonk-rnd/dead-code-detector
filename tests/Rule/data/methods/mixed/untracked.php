<?php declare(strict_types = 1);

namespace DeadMixed2;


class Clazz {

    public function getter1() {} // error: Unused DeadMixed2\Clazz::getter1
    public function getter2() {} // error: Unused DeadMixed2\Clazz::getter2
    public function getter3() {} // error: Unused DeadMixed2\Clazz::getter3
    public function getter4() {} // error: Unused DeadMixed2\Clazz::getter4
    public function getter5() {}
    public function getter6() {} // error: Unused DeadMixed2\Clazz::getter6

    public function someMethod() {} // error: Unused DeadMixed2\Clazz::someMethod
    public function nonStaticMethod() {} // error: Unused DeadMixed2\Clazz::nonStaticMethod
    public static function staticMethod() {} // error: Unused DeadMixed2\Clazz::staticMethod

}

interface IFace {

    public function getter1(); // error: Unused DeadMixed2\IFace::getter1
    public function getter2(); // error: Unused DeadMixed2\IFace::getter2
    public function getter3(); // error: Unused DeadMixed2\IFace::getter3
    public function getter4();
    public function getter5(); // error: Unused DeadMixed2\IFace::getter5

}

class Implementor implements IFace {

    public function getter1() {} // error: Unused DeadMixed2\Implementor::getter1
    public function getter2() {} // error: Unused DeadMixed2\Implementor::getter2
    public function getter3() {} // error: Unused DeadMixed2\Implementor::getter3
    public function getter4() {}
    public function getter5() {} // error: Unused DeadMixed2\Implementor::getter5
    public function getter6() {}

}

function testIt($mixed, string $notClass, object $object, IFace $iface, int|Clazz $maybeClass) {
    $mixed->getter1(); // may be valid call

    if (!$mixed instanceof Clazz) {
        $mixed->getter2(); // ideally, should mark only IFace, not Clazz (not implemented)
    }

    $object->getter3();
    $iface->getter4();
    $maybeClass->getter5(); // mark only Clazz, not IFace

    $notClass->nonStaticMethod(); // fatal error, does not count
    $notClass::staticMethod(); // may be valid call
}

function testMethodExists(Iface $iface) {
    if (method_exists($iface, 'someMethod')) {
        $iface->someMethod(); // does not not mark Clazz
    }

    $iface->getter6(); // not defined on Iface, but should mark used on its implementations but not on unrelated Clazz
}

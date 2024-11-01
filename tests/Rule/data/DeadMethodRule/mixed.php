<?php declare(strict_types = 1);

namespace DeadMixed;


class Clazz {

    public function getter1() {}
    public function getter2() {}
    public function getter3() {}
    public function getter4() {} // error: Unused DeadMixed\Clazz::getter4
    public function getter5() {}

    public function nonStaticMethod() {} // error: Unused DeadMixed\Clazz::nonStaticMethod
    public static function staticMethod() {}

}

interface IFace {

    public function getter1();
    public function getter2();
    public function getter3();
    public function getter4();
    public function getter5(); // error: Unused DeadMixed\IFace::getter5

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

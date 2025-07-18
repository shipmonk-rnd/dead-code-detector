<?php declare(strict_types = 1);

namespace DeadEnumMixed;


interface IFace {}
interface EnumIFace {}

enum MyEnum: string implements EnumIFace {

    case E_ONE = 'one';
    case E_TWO = 'two';
    case E_THREE = 'three'; // error: Unused DeadEnumMixed\MyEnum::E_THREE
    case E_FOUR = 'four';
    case E_FIVE = 'five';

    const C_ONE = 'one';
    const C_TWO = 'two';
    const C_THREE = 'three'; // error: Unused DeadEnumMixed\MyEnum::C_THREE
    const C_FOUR = 'four';
    const C_FIVE = 'five';

}

function test($mixed, object $object, IFace $iface, EnumIFace $enumIFace, string $notClass) {
    $mixed::E_ONE;
    $mixed::C_ONE;

    $object::E_TWO;
    $object::C_TWO;

    $iface::E_THREE;
    $iface::C_THREE;

    $enumIFace::E_FOUR;
    $enumIFace::C_FOUR;

    $notClass::E_FIVE;
    $notClass::C_FIVE;
}

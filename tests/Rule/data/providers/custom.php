<?php declare(strict_types = 1);

namespace CustomProvider;

class Methods
{
    const SOME_CONSTANT = 1; // error: Unused CustomProvider\Methods::SOME_CONSTANT

    public function method(): void {}
}

class Constants
{
    const SOME_CONSTANT = 1;

    public function method(): void {} // error: Unused CustomProvider\Constants::method
}

enum EnumCases: string {
    case One = 'one';
    const Two = 'two'; // error: Unused CustomProvider\EnumCases::Two

    public function method(): void {} // error: Unused CustomProvider\EnumCases::method
}

enum ConstantsInEnum: string {
    case One = 'one'; // error: Unused CustomProvider\ConstantsInEnum::One
    const Two = 'two';

    public function method(): void {} // error: Unused CustomProvider\ConstantsInEnum::method
}

enum MethodsInEnum: string {
    case One = 'one'; // error: Unused CustomProvider\MethodsInEnum::One
    const Two = 'two'; // error: Unused CustomProvider\MethodsInEnum::Two

    public function method(): void {}
}

<?php declare(strict_types = 1);

namespace CustomProvider;

class Methods
{
    const SOME_CONSTANT = 1; // error: Unused CustomProvider\Methods::SOME_CONSTANT

    public function __construct(
        public string $foo { // error: Property CustomProvider\Methods::foo is never read
            set (string $value) {
                NotPartOfCustomProvider::method(); // test that property write is derived from emitted constructor usage
            }
        },
    ) {
    }

    public function method(): void {}

    public function mixedTestThatExcludersCanExcludeProvidedUsage(): void {} // error: Unused CustomProvider\Methods::mixedTestThatExcludersCanExcludeProvidedUsage (all usages excluded by mixedPrefix excluder)
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

class NotPartOfCustomProvider {
    public static function method(): void {}
}

<?php declare(strict_types = 1);

namespace MemberTypes;

class Clazz
{
    public const CONSTANT = 1; // error: Unused MemberTypes\Clazz::CONSTANT
    public const CONSTANT_USED = 1;

    public function method(): void {} // error: Unused MemberTypes\Clazz::method

    public function methodUsed(): void {
        $this->transitivity();
    }

    private function transitivity(): void
    {
        echo MyEnum::EnumCaseUsed->name;
        echo self::CONSTANT_USED;
    }
}

enum MyEnum
{
    case EnumCase; // error: Unused MemberTypes\MyEnum::EnumCase
    case EnumCaseUsed;
}

class Address
{
    public string $address {
        get {
            return $this->address . $this->country;
        }
    }

    public string $country;
    public string $zip; // error: Property MemberTypes\Address::zip is never read
}

function test(): void {
    (new Clazz())->methodUsed();
    new Address()->address;
}

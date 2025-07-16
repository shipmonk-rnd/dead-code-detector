<?php declare(strict_types = 1);

namespace MemberTypes;

class Clazz
{
    public const CONSTANT = 1; // error: Unused MemberTypes\Clazz::CONSTANT
    public function method(): void {} // error: Unused MemberTypes\Clazz::method
}

enum MyEnum
{
    case EnumCase; // error: Unused MemberTypes\MyEnum::EnumCase
}

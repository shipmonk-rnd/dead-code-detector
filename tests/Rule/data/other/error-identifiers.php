<?php declare(strict_types = 1);

namespace ErrorIdentifiers;

class Foo
{

    public int $count = 1; // @phpstan-ignore shipmonk.deadProperty.neverRead // error: Property ErrorIdentifiers\Foo::$count is never read // error: Property ErrorIdentifiers\Foo::$count is never written

    public const BAR = 1; // @phpstan-ignore shipmonk.deadConstant

    public function method(): void {} // @phpstan-ignore shipmonk.deadMethod

}

enum MyEnum: string
{
    case MyCase = 'A'; // @phpstan-ignore shipmonk.deadEnumCase
}

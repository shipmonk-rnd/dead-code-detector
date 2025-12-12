<?php declare(strict_types = 1);

namespace MixedMemberTraitProp14;

trait SomeTrait {
    public static int $foo = 1;
}

class ParentClass {
    public static int $foo = 1; // error: Unused MixedMemberTraitProp14\ParentClass::foo
}

class User extends ParentClass {
    use SomeTrait;
}

function test(string $property)
{
    echo User::$$property;
}


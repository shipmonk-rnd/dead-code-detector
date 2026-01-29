<?php declare(strict_types = 1);

namespace MixedMemberTraitProp14;

trait SomeTrait {
    public static int $foo = 1; // error: Property MixedMemberTraitProp14\SomeTrait::$foo is never written
}

class ParentClass {
    public static int $foo = 1; // error: Property MixedMemberTraitProp14\ParentClass::$foo is never read // error: Property MixedMemberTraitProp14\ParentClass::$foo is never written
}

class User extends ParentClass {
    use SomeTrait;
}

function test(string $property)
{
    echo User::$$property;
}


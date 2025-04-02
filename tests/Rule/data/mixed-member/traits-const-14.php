<?php declare(strict_types = 1);

namespace MixedMemberTraitConst14;

trait SomeTrait {
    const FOO = 1;
}

class ParentClass {
    const FOO = 1; // error: Unused MixedMemberTraitConst14\ParentClass::FOO
}

class User extends ParentClass {
    use SomeTrait;
}

function test(string $const)
{
    echo User::{$const};
}

// this test does not fully comply with result of var_dump((new ReflectionClass('User'))->getReflectionConstants());
// this behaviour is kept for simplicity as it has equal behaviour with methods (see methods/traits-14.php)
// also, overridden constants are ensured to have the same value


<?php declare(strict_types = 1);

namespace DeadTraitConst14;

trait SomeTrait {
    const FOO = 1; // error: Unused DeadTraitConst14\SomeTrait::FOO
}

class ParentClass {
    const FOO = 1;
}

class User extends ParentClass {
    use SomeTrait;
}

echo User::FOO; // verify by var_dump((new ReflectionClass('User'))->getReflectionConstants());


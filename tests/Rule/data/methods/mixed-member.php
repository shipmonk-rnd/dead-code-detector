<?php declare(strict_types = 1);

namespace MixedMember;

interface TheInterface {
    const ZERO = 0;
}

class ParentClass
{
    const ONE = 1;
    public function one() {}
}

class Tester extends ParentClass implements TheInterface
{
    const TWO = 2;
    const THREE = 3;

    public function two() {}
    public function three() {}
}

class Descendant extends Tester
{
    const FOUR = 4;

    public function four() {}
}

function test(Tester $tester, string $unknown)
{
    echo $tester->$unknown(); // can be descendant
    echo Tester::{$unknown}; // cannot be descendant
}

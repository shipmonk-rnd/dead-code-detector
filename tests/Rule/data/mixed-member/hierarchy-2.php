<?php declare(strict_types = 1);

namespace MixedMember2;

trait TheTrait
{
    const MINUS = -1;

    public function minus() {}

    public string $minusProperty;
}

interface TheInterface {
    const ZERO = 0;
}

class ParentClass
{
    use TheTrait;

    const ONE = 1;
    public function one() {}

    public string $oneProperty;
}

class Tester extends ParentClass implements TheInterface
{
    const TWO = 2;
    const THREE = 3;

    public function two() {}
    public function three() {}

    public string $twoProperty;
    public string $threeProperty;
}

class Descendant extends Tester
{
    const FOUR = 4; // error: Unused MixedMember2\Descendant::FOUR

    public function four() {} // error: Unused MixedMember2\Descendant::four

    public string $fourProperty; // error: Unused MixedMember2\Descendant::fourProperty
}

function test(string $unknown)
{
    echo (new Tester)->$unknown(); // cannot be descendant
    echo Tester::{$unknown}; // cannot be descendant
    echo (new Tester)->$unknown; // cannot be descendant
}

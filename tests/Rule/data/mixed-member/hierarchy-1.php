<?php declare(strict_types = 1);

namespace MixedMember1;

trait TheTrait
{
    const MINUS = -1;

    public function minus() {}

    public string $minusProperty; // error: Property MixedMember1\TheTrait::$minusProperty is never written
}

interface TheInterface {
    const ZERO = 0;
}

class ParentClass
{
    use TheTrait;

    const ONE = 1;
    public function one() {}

    public string $oneProperty; // error: Property MixedMember1\ParentClass::$oneProperty is never written
}

class Tester extends ParentClass implements TheInterface
{
    const TWO = 2;
    const THREE = 3;

    public function two() {}
    public function three() {}

    public string $twoProperty; // error: Property MixedMember1\Tester::$twoProperty is never written
    public string $threeProperty; // error: Property MixedMember1\Tester::$threeProperty is never written
}

class Descendant extends Tester
{
    const FOUR = 4;

    public function four() {}

    public string $fourProperty; // error: Property MixedMember1\Descendant::$fourProperty is never written
}

function test(Tester $tester, string $unknown)
{
    echo $tester->$unknown(); // can be descendant
    echo $tester::{$unknown}; // can be descendant
    echo $tester->$unknown; // can be descendant
}

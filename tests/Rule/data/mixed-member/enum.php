<?php declare(strict_types = 1);

namespace MixedMemberEnum;


enum Tester: string
{
    case ONE = 'one';
    case TWO = 'two';
    const THREE = 'three';
}


function test(Tester $tester, string $unknown)
{
    echo $tester::{$unknown}; // can be descendant
}

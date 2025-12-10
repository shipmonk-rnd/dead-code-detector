<?php declare(strict_types = 1);

namespace MixedMemberTraitProp7;

trait HelloWorld {
    public static int $hello = 1; // error: Unused MixedMemberTraitProp7\HelloWorld::hello
}

class TheWorldIsNotEnough {
    use HelloWorld;

    public static int $hello = 1;
}

function test(string $property)
{
    $o = new TheWorldIsNotEnough();
    echo $o::$$property;
}

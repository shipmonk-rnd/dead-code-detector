<?php

namespace DebugExclude;

class Foo
{
    public static function mixedExcluder1() {} // error: Unused DebugExclude\Foo::mixedExcluder1
    public static function mixedExcluder2() {} // error: Unused DebugExclude\Foo::mixedExcluder2
}

class Chld extends Foo {

}

Chld::mixedExcluder1();
Foo::mixedExcluder2();


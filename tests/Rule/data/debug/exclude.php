<?php

namespace DebugExclude;

class Foo
{
    public static function mixedExcluder1() {} // error: Unused DebugExclude\Foo::mixedExcluder1 (all usages excluded by mixed excluder)
    public static function mixedExcluder2() {} // error: Unused DebugExclude\Foo::mixedExcluder2 (all usages excluded by mixed excluder)
}

class Chld extends Foo {

}

Chld::mixedExcluder1();
Foo::mixedExcluder2();


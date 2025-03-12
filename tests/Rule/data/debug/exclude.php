<?php

namespace DebugExclude;

class Foo
{
    public static function mixedExcluder() {} // error: Unused DebugExclude\Foo::mixedExcluder (all usages excluded by mixed excluder)
}

Foo::mixedExcluder();


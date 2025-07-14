<?php

namespace DebugEnum;

enum Foo: string
{
    case One = 'one'; // error: Unused DebugEnum\Foo::One
    case Two = 'two';

}

echo Foo::Two->value;


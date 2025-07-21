<?php

namespace DebugBuiltin;


class Iter implements \IteratorAggregate
{
    public function getIterator()
    {
        yield 1;
    }
}



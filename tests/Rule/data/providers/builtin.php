<?php

namespace BuiltinProvider;

class MyIterator implements \IteratorAggregate
{
    public function getIterator(): \Traversable
    {
        yield 1;
    }
}

class MyException extends \Exception {
    protected $message;
    public $dead; // error: Property BuiltinProvider\MyException::$dead is never read // error: Property BuiltinProvider\MyException::$dead is never written

}

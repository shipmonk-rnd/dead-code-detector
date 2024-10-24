<?php declare(strict_types = 1);

namespace Callables;


class Foo {

    public function test() // error: Unused Callables\Foo::test
    {
        return function () {
            // this is still using transitive detection even though it is not immediately called
            // because if test() method is never called, this Closure will never be created and called
            $this->transitive();
        };
    }

    public function transitive(): void // error: Unused Callables\Foo::transitive
    {
    }

}


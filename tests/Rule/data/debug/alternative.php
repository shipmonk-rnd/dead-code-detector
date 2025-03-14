<?php

namespace DebugAlternative;

trait Foo {
    public function foo() {}
}

class Clazz {
    use Foo;
}

(new Clazz())->foo();

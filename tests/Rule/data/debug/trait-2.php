<?php

namespace DebugTrait;

class User {
    use TrueOrigin;

    public function foo() {}
}

(new User())->origin();

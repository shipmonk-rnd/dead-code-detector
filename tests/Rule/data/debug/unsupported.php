<?php

namespace DebugUnsupported;

class Foo {
    public function __destruct()
    {
        $this->notDead();
    }

    private function notDead()
    {
    }
}

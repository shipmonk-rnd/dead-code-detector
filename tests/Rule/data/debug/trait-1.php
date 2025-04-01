<?php

namespace DebugTrait;


trait TrueOrigin {

    public function origin()
    {
        $this->foo();
    }

}

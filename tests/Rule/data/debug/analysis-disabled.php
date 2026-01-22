<?php

namespace DebugAnalysisDisabled;

class X
{
    public $property;

    const CONSTANT = 1;

    public function method(): void
    {
        $this->method();
        $this->property = 1;
        echo $this->property;
        echo self::CONSTANT;
    }
}

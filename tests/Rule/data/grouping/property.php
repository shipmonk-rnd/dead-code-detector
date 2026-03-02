<?php declare(strict_types = 1);

namespace GroupingProperty;

readonly class Writer
{
    public function __construct(
        public string $data
    )
    {
        echo $this->data;
    }
}



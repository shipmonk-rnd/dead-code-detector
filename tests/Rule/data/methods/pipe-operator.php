<?php declare(strict_types = 1);

namespace DeadPipeOperator;

class StringProcessor {

    public function process(string $input): string
    {
        return $input |> $this->trim(...) |> $this->uppercase(...);
    }

    public function trim(string $str): string
    {
        return trim($str);
    }

    public function uppercase(string $str): string
    {
        return strtoupper($str);
    }

    public function lowercase(string $str): string // error: Unused DeadPipeOperator\StringProcessor::lowercase
    {
        return strtolower($str);
    }
}

function test() {
    $processor = new StringProcessor()->process('  Hello World  ');
}

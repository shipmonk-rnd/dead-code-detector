<?php declare(strict_types = 1);

namespace DeadConstDynamic;

class Test {

    const A = 'a';
    const B = 'b';
    const C = 'c'; // error: Unused DeadConstDynamic\Test::C
}

/**
 * @param 'A'|'B' $const
 */
function test(string $const): void {
    echo Test::{$const}; // since PHP 8.3
}


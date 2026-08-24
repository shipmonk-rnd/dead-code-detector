<?php declare(strict_types = 1);

namespace DeadConstFn;

class TestParent {
    const B = 'b';
    const C = 'c'; // error: Unused DeadConstFn\TestParent::C
    const D = 'd';
    const E = 'e';
}

class Test extends TestParent {
    const A = 'a';
}


function test() {
    $fn = 'constant';
    $upperFn = 'CONSTANT';
    echo constant('DeadConstFn\Test::A');
    echo constant('Unknown::A');
    echo $fn('\DeadConstFn\Test::B');
    echo CONSTANT('DeadConstFn\Test::D'); // function names are case-insensitive
    echo $upperFn('\DeadConstFn\Test::E');
}

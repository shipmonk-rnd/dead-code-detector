<?php declare(strict_types = 1);

namespace DeadConstFn;

class TestParent {
    const B = 'b';
    const C = 'c'; // error: Unused DeadConstFn\TestParent::C
}

class Test extends TestParent {
    const A = 'a';
}


$fn = 'constant';
echo constant('DeadConstFn\Test::A');
echo constant('Unknown::A');
echo $fn('\DeadConstFn\Test::B');

<?php

namespace MixedExcluder;

class SomeParent {
    public function mixed1() {} // error: Unused MixedExcluder\SomeParent::mixed1 (all usages excluded by mixedPrefix excluder)
}

class Some extends SomeParent {
    public function mixed2() {} // error: Unused MixedExcluder\Some::mixed2 (all usages excluded by mixedPrefix excluder)
}

function test(Some $some) {
    $some->mixed1();
    $some->mixed2();
}

<?php

class Some {
    public function mixed() { // error: Unused Some::mixed (all usages excluded by mixed excluder)
        return 1;
    }
}

function test(object $object) {
    $object->mixed();
}

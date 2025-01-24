<?php

class Some {
    public function mixed() { // error: Unused Some::mixed
        return 1;
    }
}

function test(object $object) {
    $object->mixed();
}

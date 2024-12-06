<?php declare(strict_types = 1);

namespace DeadConstMagic;

class Magic {

    const FOO = 1;

    public function __invoke() {
        echo self::FOO;
    }

}


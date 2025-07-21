<?php declare(strict_types = 1);

namespace FetchSoftFinal;

/**
 * @final
 */
class SoftFinalParent
{
    const FOO = 'foo';

    public function __construct() {
        echo $this::FOO;
    }
}

class Child extends SoftFinalParent
{
    const FOO = 'bar';
}


new Child();


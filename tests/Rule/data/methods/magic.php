<?php declare(strict_types = 1);

namespace DeadMagic;


class Magic {

    public static function create(): self {
        return new self();
    }

    private function __construct() {
        $this->calledFromPrivateConstruct();
    }

    public function __invoke() {
        $this->calledFromInvoke();
    }

    public function __destruct()
    {
        $this->calledFromDestruct();
    }

    public function __get($what)
    {
        $this->calledFromGet();
    }

    public function calledFromInvoke() {}
    public function calledFromDestruct() {}
    public function calledFromPrivateConstruct() {}
    public function calledFromGet() {}

}

function test() {
    $invokable = Magic::create();
    $invokable->magic();
    $invokable();
}

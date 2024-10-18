<?php declare(strict_types = 1);

namespace DeadMagic;


class Magic {
    public function __invoke() {
        $this->calledFromInvoke();
    }

    public function __destruct()
    {
        $this->calledFromDestruct();
    }

    public function __get()
    {
        $this->calledFromGet();
    }

    public function calledFromInvoke() {

    }

    public function calledFromDestruct() {

    }

    public function calledFromGet() {

    }

}

$invokable = new Magic();
$invokable->magic();
$invokable();

<?php declare(strict_types = 1);

namespace DeadTrait4;

trait MyTrait1 {

    public function __construct() // TODO is dead
    {
    }
}

class MyUser1
{
    use MyTrait1;

    public function __construct()
    {
    }
}


new MyUser1();

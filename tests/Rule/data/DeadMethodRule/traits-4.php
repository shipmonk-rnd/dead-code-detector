<?php declare(strict_types = 1);

namespace DeadTrait4;

trait MyTrait1 {

    public function __construct()
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

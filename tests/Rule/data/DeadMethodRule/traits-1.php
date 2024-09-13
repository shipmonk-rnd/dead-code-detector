<?php declare(strict_types = 1);

namespace DeadTrait1;

trait Trait1 {

    public static function used(): void {}
    public static function unused(): void {}
}

class User1
{
    use Trait1;
}

class User2
{
    use Trait1;
}

User2::used();

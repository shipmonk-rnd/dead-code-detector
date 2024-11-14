<?php declare(strict_types = 1);

namespace DeadTraitConst1;

trait Trait1 {

    const USED = 1;
    const UNUSED = 2; // error: Unused DeadTraitConst1\Trait1::UNUSED
}

class User1
{
    use Trait1;
}

class User2
{
    use Trait1;
}

echo User2::USED;

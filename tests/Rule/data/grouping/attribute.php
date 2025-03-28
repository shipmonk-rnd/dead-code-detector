<?php declare(strict_types = 1);

namespace AttributeGrouping;

class Foo
{
    const USED_IN_ATTRIBUTE = 1;

    #[Foo(self::USED_IN_ATTRIBUTE)] // the limitation here is that this usage is currently not considered as transitive
    public function endpoint(): void
    {
    }

}


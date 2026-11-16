<?php declare(strict_types = 1);

namespace Removal;

class ClassWithFullyDeadGroups
{

    public function used(): void
    {
    }

}

function useFullyDeadGroups(): void
{
    (new ClassWithFullyDeadGroups())->used();
}

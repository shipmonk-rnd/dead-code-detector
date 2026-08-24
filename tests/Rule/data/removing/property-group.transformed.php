<?php declare(strict_types = 1);

namespace Removal;

class ClassWithPropertyGroup
{

    public int $usedGrouped = 2;

    public function getUsed(): int
    {
        return $this->usedGrouped;
    }

}

function usePropertyGroup(): void
{
    echo (new ClassWithPropertyGroup())->getUsed();
}

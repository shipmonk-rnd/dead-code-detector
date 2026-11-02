<?php declare(strict_types = 1);

namespace Removal;

class ClassWithFullyDeadGroups
{

    public const DEAD_A = 1, DEAD_B = 2;

    public int $deadA = 1, $deadB = 2;

    public function dead(): int
    {
        return self::DEAD_A + self::DEAD_B + $this->deadA + $this->deadB;
    }

    public function used(): void
    {
    }

}

function useFullyDeadGroups(): void
{
    (new ClassWithFullyDeadGroups())->used();
}

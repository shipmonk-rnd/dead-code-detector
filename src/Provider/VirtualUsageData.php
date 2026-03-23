<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

final class VirtualUsageData
{

    private function __construct(
        private readonly string $note,
    )
    {
    }

    /**
     * @param string $note More detailed info why provider emitted this virtual usage
     */
    public static function withNote(string $note): self
    {
        return new self($note);
    }

    public function getNote(): string
    {
        return $this->note;
    }

}

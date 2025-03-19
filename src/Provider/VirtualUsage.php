<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

class VirtualUsage
{

    private string $note;

    private function __construct(string $note)
    {
        $this->note = $note;
    }

    public static function withNote(string $note): self
    {
        return new self($note);
    }

    public function getNote(): string
    {
        return $this->note;
    }

}

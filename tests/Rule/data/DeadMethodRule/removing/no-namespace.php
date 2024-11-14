<?php declare(strict_types = 1);

interface Remove
{
    const DEAD = 1;
    public function dead(): void;
}

<?php declare(strict_types = 1);

interface Remove
{
    public function dead(): void // error: Unused Remove::dead
    ;
}

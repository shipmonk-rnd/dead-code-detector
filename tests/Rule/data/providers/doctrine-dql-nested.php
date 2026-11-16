<?php declare(strict_types = 1);

namespace DoctrineDqlNested;

use Doctrine\ORM\EntityManagerInterface;

class OuterDto
{
    public function __construct(int $id, InnerDto $inner) {}
}

class InnerDto
{
    public function __construct(string $name) {}
}

function test(EntityManagerInterface $em): void
{
    $em->createQuery('SELECT NEW DoctrineDqlNested\OuterDto(u.id, NEW DoctrineDqlNested\InnerDto(u.name)) FROM DoctrineDql\User u');
}

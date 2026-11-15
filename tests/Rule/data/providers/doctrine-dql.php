<?php declare(strict_types = 1);

namespace DoctrineDql;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

class SelectDto
{
    public function __construct(int $id) {}
}

class AddSelectDto
{
    public function __construct(int $id) {}
}

class ArraySelectDto
{
    public function __construct(int $id) {}
}

class CreateQueryDto
{
    public function __construct(int $id) {}
}

class SetDqlDto
{
    public function __construct(int $id) {}
}

class NamedDto
{
    public function __construct(int $id) {}
}

class LeadingBackslashDto
{
    public function __construct(int $id) {}
}

class ParentDto
{
    public function __construct(int $id) {}
}

class ChildDto extends ParentDto
{
}

class UnusedDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\UnusedDto::__construct
}

class OtherMethodDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\OtherMethodDto::__construct
}

function test(EntityManagerInterface $em, QueryBuilder $qb, Query $query, object $other): void
{
    $qb->select(sprintf('NEW %s(e.id)', SelectDto::class));
    $qb->addSelect('new DoctrineDql\AddSelectDto(e.id)');
    $qb->select(['e.id', 'NEW DoctrineDql\ArraySelectDto(e.id)']);
    $em->createQuery('SELECT NEW DoctrineDql\CreateQueryDto(e.id) FROM DoctrineDql\Entity e');
    $query->setDQL('SELECT NEW NAMED DoctrineDql\NamedDto(e.id AS id), NEW DoctrineDql\SetDqlDto(e.id) FROM DoctrineDql\Entity e');
    $qb->select('NEW \DoctrineDql\LeadingBackslashDto(e.id)');
    $qb->select('NEW DoctrineDql\ChildDto(e.id)');
    $other->select('NEW DoctrineDql\OtherMethodDto(e.id)');
}

<?php declare(strict_types = 1);

namespace DoctrineDql;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;

#[ORM\Entity]
class User
{

    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $name;

    public function __construct() // error: Unused DoctrineDql\User::__construct
    {
    }

}

class CreateQueryDto
{
    public function __construct(int $id) {}
}

class LowercaseDto
{
    public function __construct(int $id) {}
}

class ShortNameDto
{
    public function __construct(int $id) {}
}

class LeadingBackslashDto
{
    public function __construct(int $id) {}
}

class SprintfDto
{
    public function __construct(int $id) {}
}

class QueryBuilderDto
{
    public function __construct(int $id) {}
}

class BranchADto
{
    public function __construct(int $id) {}
}

class BranchBDto
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

class StringLiteralDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\StringLiteralDto::__construct
}

class InvalidDqlDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\InvalidDqlDto::__construct
}

class UntrackedQueryBuilderDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\UntrackedQueryBuilderDto::__construct
}

class NeverSelectedDto
{
    public function __construct(int $id) {} // error: Unused DoctrineDql\NeverSelectedDto::__construct
}

function test(EntityManagerInterface $em, QueryBuilder $untrackedQueryBuilder, bool $condition): void
{
    $em->createQuery('SELECT NEW DoctrineDql\CreateQueryDto(u.id) FROM DoctrineDql\User u');
    $em->createQuery('select new DoctrineDql\LowercaseDto(u.id) from DoctrineDql\User u');
    $em->createQuery('SELECT NEW ShortNameDto(u.id) FROM DoctrineDql\User u');
    $em->createQuery('SELECT NEW \DoctrineDql\LeadingBackslashDto(u.id) FROM DoctrineDql\User u');
    $em->createQuery(sprintf('SELECT NEW %s(u.id) FROM %s u', SprintfDto::class, User::class));
    $em->createQuery('SELECT NEW DoctrineDql\ChildDto(u.id) FROM DoctrineDql\User u');
    $em->createQuery($condition
        ? 'SELECT NEW DoctrineDql\BranchADto(u.id) FROM DoctrineDql\User u'
        : 'SELECT NEW DoctrineDql\BranchBDto(u.id) FROM DoctrineDql\User u');

    $em->createQueryBuilder()
        ->select('NEW DoctrineDql\QueryBuilderDto(u.id)')
        ->from(User::class, 'u')
        ->getQuery();

    $em->createQuery('SELECT u FROM DoctrineDql\User u WHERE u.name = \'NEW DoctrineDql\StringLiteralDto(u.id)\'');
    $em->createQuery('SELECT NEW DoctrineDql\InvalidDqlDto(u.unknown) FROM DoctrineDql\User u');
    $em->createQuery('UPDATE DoctrineDql\User u SET u.name = \'new\'');

    $untrackedQueryBuilder
        ->select('NEW DoctrineDql\UntrackedQueryBuilderDto(u.id)')
        ->from(User::class, 'u')
        ->getQuery();
}

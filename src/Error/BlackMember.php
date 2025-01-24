<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Error;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use function array_keys;
use function count;
use function implode;

final class BlackMember
{

    private ClassMemberRef $member;

    private string $file;

    private int $line;

    /**
     * @var array<string, true>
     */
    private array $excluders = [];

    public function __construct(
        ClassMemberRef $member,
        string $file,
        int $line
    )
    {
        if ($member->getClassName() === null) {
            throw new LogicException('Class name must be known');
        }

        if ($member->isPossibleDescendant()) {
            throw new LogicException('Using possible descendant does not make sense here');
        }

        $this->member = $member;
        $this->file = $file;
        $this->line = $line;
    }

    public function getMember(): ClassMemberRef
    {
        return $this->member;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function markHasExcludedUsage(string $excludedBy): void
    {
        $this->excluders[$excludedBy] = true;
    }

    public function getErrorIdentifier(): string
    {
        return $this->member instanceof ClassConstantRef
            ? DeadCodeRule::IDENTIFIER_CONSTANT
            : DeadCodeRule::IDENTIFIER_METHOD;
    }

    public function getExclusionMessage(): string
    {
        if (count($this->excluders) === 0) {
            return '';
        }

        $excluderNames = implode(', ', array_keys($this->excluders));
        $plural = count($this->excluders) > 1 ? 's' : '';

        return " (all usages excluded by {$excluderNames} excluder{$plural})";
    }

}

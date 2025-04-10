<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Error;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\EnumCaseRef;
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
     * @var array<string, list<ClassMemberUsage>>
     */
    private array $excludedUsages = [];

    public function __construct(
        ClassMemberRef $member,
        string $file,
        int $line
    )
    {
        if ($member->getClassName() === null) {
            throw new LogicException('Class name must be known');
        }

        if ($member->getMemberName() === null) {
            throw new LogicException('Member name must be known');
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

    public function addExcludedUsage(CollectedUsage $excludedUsage): void
    {
        if (!$excludedUsage->isExcluded()) {
            throw new LogicException('Given usage is not excluded!');
        }

        $excludedBy = $excludedUsage->getExcludedBy();

        $this->excludedUsages[$excludedBy][] = $excludedUsage->getUsage();
    }

    public function getErrorIdentifier(): string
    {
        if ($this->member instanceof ClassConstantRef) {
            return DeadCodeRule::IDENTIFIER_CONSTANT;
        } elseif ($this->member instanceof ClassMethodRef) {
            return DeadCodeRule::IDENTIFIER_METHOD;
        } elseif ($this->member instanceof EnumCaseRef) {
            return DeadCodeRule::IDENTIFIER_ENUM_CASE;
        } else {
            throw new LogicException('Unknown member type');
        }
    }

    public function getExclusionMessage(): string
    {
        if (count($this->excludedUsages) === 0) {
            return '';
        }

        $excluderNames = implode(', ', array_keys($this->excludedUsages));
        $plural = count($this->excludedUsages) > 1 ? 's' : '';

        return " (all usages excluded by {$excluderNames} excluder{$plural})";
    }

    /**
     * @return list<ClassMemberUsage>
     */
    public function getExcludedUsages(): array
    {
        $result = [];

        foreach ($this->excludedUsages as $usages) {
            foreach ($usages as $usage) {
                $result[] = $usage;
            }
        }

        return $result;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Error;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;
use function array_keys;
use function count;
use function implode;

final class BlackMember
{

    /**
     * @var ClassMemberRef<string, string>
     */
    private ClassMemberRef $member;

    private string $file;

    private int $line;

    /**
     * @var array<string, list<ClassMemberUsage>>
     */
    private array $excludedUsages = [];

    /**
     * @param ClassMemberRef<string, string> $member
     */
    public function __construct(
        ClassMemberRef $member,
        string $file,
        int $line
    )
    {
        if ($member->isPossibleDescendant()) {
            throw new LogicException('Using possible descendant does not make sense here');
        }

        if ($member instanceof ClassConstantRef && $member->isEnumCase()->maybe()) {
            throw new LogicException('Black member cannot be unresolved, it references definition, not usage');
        }

        $this->member = $member;
        $this->file = $file;
        $this->line = $line;
    }

    /**
     * @return ClassMemberRef<string, string>
     */
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
            if ($this->member->isEnumCase()->yes()) {
                return DeadCodeRule::IDENTIFIER_ENUM_CASE;

            } elseif ($this->member->isEnumCase()->no()) {
                return DeadCodeRule::IDENTIFIER_CONSTANT;

            } else {
                throw new LogicException('Cannot happen, ensured in constructor');
            }

        } elseif ($this->member instanceof ClassMethodRef) {
            return DeadCodeRule::IDENTIFIER_METHOD;

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

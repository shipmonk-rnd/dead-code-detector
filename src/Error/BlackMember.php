<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Error;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Rule\DeadCodeRule;

/**
 * @immutable
 */
class BlackMember
{

    public ClassMemberRef $member;

    public string $file;

    public int $line;

    public function __construct(
        ClassMemberRef $member,
        string $file,
        int $line
    )
    {
        if ($member->className === null) {
            throw new LogicException('Class name must be known');
        }

        if ($member->possibleDescendant) {
            throw new LogicException('Using possible descendant does not make sense here');
        }

        $this->member = $member;
        $this->file = $file;
        $this->line = $line;
    }

    public function getErrorIdentifier(): string
    {
        return $this->member->memberType === ClassMemberRef::TYPE_CONSTANT
            ? DeadCodeRule::IDENTIFIER_CONSTANT
            : DeadCodeRule::IDENTIFIER_METHOD;
    }

}

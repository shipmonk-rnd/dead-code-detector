<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
abstract class ClassMemberUsage
{

    /**
     * Origin method of the usage, "where it was called from"
     * This is required for proper transitive dead code detection.
     *
     * @see UsageOriginDetector for typical usage
     */
    private ?ClassMethodRef $origin;

    public function __construct(?ClassMethodRef $origin)
    {
        if ($origin !== null && $origin->isPossibleDescendant()) {
            throw new LogicException('Origin should always be exact place in codebase.');
        }

        if ($origin !== null && $origin->getClassName() === null) {
            throw new LogicException('Origin should always be exact place in codebase, thus className should be known.');
        }

        $this->origin = $origin;
    }

    public function getOrigin(): ?ClassMethodRef
    {
        return $this->origin;
    }

    /**
     * @return MemberType::*
     */
    abstract public function getMemberType(): int;

    abstract public function getMemberRef(): ClassMemberRef;

    /**
     * @return static
     */
    abstract public function concretizeMixedUsage(string $className): self;

    public function toHumanString(): string
    {
        $origin = $this->origin !== null ? $this->origin->toHumanString() : 'unknown';
        $callee = $this->getMemberRef()->toHumanString();

        return "$origin -> $callee";
    }

}

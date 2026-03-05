<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
abstract class ClassMemberUsage
{

    /**
     * Origin method of the usage, "where it was called from"
     * This is required for proper transitive dead code detection.
     */
    private UsageOrigin $origin;

    public function __construct(UsageOrigin $origin)
    {
        $this->origin = $origin;
    }

    public function getOrigin(): UsageOrigin
    {
        return $this->origin;
    }

    /**
     * @return MemberType::*
     */
    abstract public function getMemberType(): int;

    /**
     * @return ClassMemberRef<string|null, string|null>
     */
    abstract public function getMemberRef(): ClassMemberRef;

    /**
     * @return AccessType::*
     */
    abstract public function getAccessType(): int;

    /**
     * If false, causes member to be marked as white, but stops graph propagation.
     */
    abstract public function isPropagating(): bool;

    /**
     * @return static
     */
    abstract public function concretizeMixedClassNameUsage(string $className): self;

}

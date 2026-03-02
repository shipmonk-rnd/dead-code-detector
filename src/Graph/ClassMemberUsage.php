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
     * @param UsageOrigin $origin Origin method of the usage, "where it was called from". Required for proper transitive dead code detection.
     */
    public function __construct(
        private readonly UsageOrigin $origin,
    )
    {
    }

    public function getOrigin(): UsageOrigin
    {
        return $this->origin;
    }

    abstract public function getMemberType(): MemberType;

    /**
     * @return ClassMemberRef<string|null, string|null>
     */
    abstract public function getMemberRef(): ClassMemberRef;

    abstract public function getAccessType(): AccessType;

    /**
     * @return static
     */
    abstract public function concretizeMixedClassNameUsage(string $className): self;

}

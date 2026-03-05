<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassPropertyUsage extends ClassMemberUsage
{

    /**
     * @var ClassPropertyRef<string|null, string|null>
     */
    private ClassPropertyRef $access;

    /**
     * @var AccessType::*
     */
    private int $accessType;

    private bool $callsHook;

    /**
     * @param UsageOrigin $origin The method where the read occurs
     * @param ClassPropertyRef<string|null, string|null> $access The property being read
     * @param AccessType::* $accessType
     * @param bool $callsHook Only self-referencing property access inside a hook does not call property hook
     */
    public function __construct(
        UsageOrigin $origin,
        ClassPropertyRef $access,
        int $accessType,
        bool $callsHook = true
    )
    {
        parent::__construct($origin);
        $this->access = $access;
        $this->accessType = $accessType;
        $this->callsHook = $callsHook;
    }

    /**
     * @return MemberType::PROPERTY
     */
    public function getMemberType(): int
    {
        return MemberType::PROPERTY;
    }

    /**
     * @return ClassPropertyRef<string|null, string|null>
     */
    public function getMemberRef(): ClassPropertyRef
    {
        return $this->access;
    }

    public function getAccessType(): int
    {
        return $this->accessType;
    }

    public function concretizeMixedClassNameUsage(string $className): self
    {
        if ($this->access->getClassName() !== null) {
            throw new LogicException('Usage is not mixed, thus it cannot be concretized');
        }

        return new self(
            $this->getOrigin(),
            new ClassPropertyRef(
                $className,
                $this->access->getMemberName(),
                $this->access->isPossibleDescendant(),
            ),
            $this->accessType,
        );
    }

    public function isPropagating(): bool
    {
        return $this->callsHook;
    }

}

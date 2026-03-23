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
    private readonly ClassPropertyRef $access;

    private readonly AccessType $accessType;

    private readonly bool $callsHook;

    /**
     * @param UsageOrigin $origin The method where the read occurs
     * @param ClassPropertyRef<string|null, string|null> $access The property being read
     * @param bool $callsHook Only self-referencing property access inside a hook does not call property hook
     */
    public function __construct(
        UsageOrigin $origin,
        ClassPropertyRef $access,
        AccessType $accessType,
        bool $callsHook = true,
    )
    {
        parent::__construct($origin);
        $this->access = $access;
        $this->accessType = $accessType;
        $this->callsHook = $callsHook;
    }

    public function getMemberType(): MemberType
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

    public function getAccessType(): AccessType
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

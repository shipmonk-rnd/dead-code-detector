<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassConstantUsage extends ClassMemberUsage
{

    private ClassConstantRef $fetch;

    /**
     * @param UsageOrigin $origin The method where the fetch occurs
     * @param ClassConstantRef $fetch The fetch of the constant
     */
    public function __construct(
        UsageOrigin $origin,
        ClassConstantRef $fetch
    )
    {
        parent::__construct($origin);
        $this->fetch = $fetch;
    }

    /**
     * @return MemberType::CONSTANT
     */
    public function getMemberType(): int
    {
        return MemberType::CONSTANT;
    }

    public function getMemberRef(): ClassConstantRef
    {
        return $this->fetch;
    }

    public function concretizeMixedClassNameUsage(string $className): self
    {
        if ($this->fetch->getClassName() !== null) {
            throw new LogicException('Usage is not mixed, thus it cannot be concretized');
        }

        return new self(
            $this->getOrigin(),
            new ClassConstantRef(
                $className,
                $this->fetch->getMemberName(),
                $this->fetch->isPossibleDescendant(),
            ),
        );
    }

    public function concretizeMixedMemberNameUsage(string $memberName): ClassMemberUsage
    {
        if ($this->fetch->getMemberName() !== null) {
            throw new LogicException('Usage is not mixed, thus it cannot be concretized');
        }

        return new self(
            $this->getOrigin(),
            new ClassConstantRef(
                $this->fetch->getClassName(),
                $memberName,
                $this->fetch->isPossibleDescendant(),
            ),
        );
    }

}

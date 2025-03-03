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
     * @param ClassMethodRef|null $origin The method where the call occurs
     * @param ClassConstantRef $fetch The fetch of the constant
     */
    public function __construct(
        ?ClassMethodRef $origin,
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

    public function concretizeMixedUsage(string $className): self
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

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassMethodUsage extends ClassMemberUsage
{

    private ClassMethodRef $callee;

    /**
     * @param ClassMethodRef|null $origin The method where the call occurs
     * @param ClassMethodRef $callee The method being called
     */
    public function __construct(
        ?ClassMethodRef $origin,
        ClassMethodRef $callee
    )
    {
        parent::__construct($origin);

        $this->callee = $callee;
    }

    /**
     * @return MemberType::METHOD
     */
    public function getMemberType(): int
    {
        return MemberType::METHOD;
    }

    public function getMemberRef(): ClassMethodRef
    {
        return $this->callee;
    }

    public function concretizeMixedUsage(string $className): self
    {
        if ($this->callee->getClassName() !== null) {
            throw new LogicException('Usage is not mixed, thus it cannot be concretized');
        }

        return new self(
            $this->getOrigin(),
            new ClassMethodRef(
                $className,
                $this->callee->getMemberName(),
                $this->callee->isPossibleDescendant(),
            ),
        );
    }

}

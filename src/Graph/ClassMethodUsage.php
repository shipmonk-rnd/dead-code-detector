<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassMethodUsage extends ClassMemberUsage
{

    /**
     * @var ClassMethodRef<string|null, string|null>
     */
    private ClassMethodRef $callee;

    /**
     * @param UsageOrigin $origin The method where the call occurs
     * @param ClassMethodRef<string|null, string|null> $callee The method being called
     */
    public function __construct(
        UsageOrigin $origin,
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

    /**
     * @return ClassMethodRef<string|null, string|null>
     */
    public function getMemberRef(): ClassMethodRef
    {
        return $this->callee;
    }

    public function concretizeMixedClassNameUsage(string $className): self
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

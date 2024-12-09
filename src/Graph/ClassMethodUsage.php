<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

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
     * @return ClassMemberRef::TYPE_METHOD
     */
    public function getMemberType(): int
    {
        return ClassMemberRef::TYPE_METHOD;
    }

    public function getMemberRef(): ClassMethodRef
    {
        return $this->callee;
    }

}

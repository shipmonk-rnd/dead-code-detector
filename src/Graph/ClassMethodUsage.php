<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
class ClassMethodUsage extends ClassMemberUsage
{

    private ClassMethodRef $callee;

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

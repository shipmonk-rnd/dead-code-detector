<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class ClassMethodCall extends ClassMemberUsage
{

    private ClassMethodRef $callee;

    public function __construct(
        ?ClassMethodRef $origin,
        ClassMethodRef $callee,
        bool $possibleDescendantCall
    )
    {
        parent::__construct($origin, $possibleDescendantCall);

        $this->callee = $callee;
    }

    /**
     * @return ClassMemberRef::TYPE_METHOD
     */
    public function getMemberType(): int
    {
        return ClassMemberRef::TYPE_METHOD;
    }

    public function getMemberUsage(): ClassMethodRef
    {
        return $this->callee;
    }

}

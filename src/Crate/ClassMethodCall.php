<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class ClassMethodCall extends ClassMemberUse
{

    private ClassMethodRef $callee;

    public function __construct(
        ?ClassMethodRef $caller,
        ClassMethodRef $callee,
        bool $possibleDescendantCall
    )
    {
        parent::__construct($caller, $possibleDescendantCall);

        $this->callee = $callee;
    }

    /**
     * @return ClassMemberRef::TYPE_METHOD
     */
    public function getMemberType(): int
    {
        return ClassMemberRef::TYPE_METHOD;
    }

    public function getMemberUse(): ClassMethodRef
    {
        return $this->callee;
    }

}

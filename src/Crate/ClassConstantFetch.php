<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class ClassConstantFetch extends ClassMemberUsage
{

    private ClassConstantRef $fetch;

    public function __construct(
        ?ClassMethodRef $origin, // TODO always known class, introduce new type?
        ClassConstantRef $fetch,
        bool $possibleDescendantFetch
    )
    {
        parent::__construct($origin, $possibleDescendantFetch);
        $this->fetch = $fetch;
    }

    /**
     * @return ClassMemberRef::TYPE_CONSTANT
     */
    public function getMemberType(): int
    {
        return ClassMemberRef::TYPE_CONSTANT;
    }

    public function getMemberUsage(): ClassConstantRef
    {
        return $this->fetch;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
class ClassConstantUsage extends ClassMemberUsage
{

    private ClassConstantRef $fetch;

    public function __construct(
        ?ClassMethodRef $origin,
        ClassConstantRef $fetch
    )
    {
        parent::__construct($origin);
        $this->fetch = $fetch;
    }

    /**
     * @return ClassMemberRef::TYPE_CONSTANT
     */
    public function getMemberType(): int
    {
        return ClassMemberRef::TYPE_CONSTANT;
    }

    public function getMemberRef(): ClassConstantRef
    {
        return $this->fetch;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

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

}

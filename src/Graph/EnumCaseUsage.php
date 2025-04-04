<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class EnumCaseUsage extends ClassMemberUsage
{

    private EnumCaseRef $enumCase;

    /**
     * @param UsageOrigin $origin The method where the fetch occurs
     * @param EnumCaseRef $enumCase The case being fetched
     */
    public function __construct(
        UsageOrigin $origin,
        EnumCaseRef $enumCase
    )
    {
        parent::__construct($origin);

        $this->enumCase = $enumCase;
    }

    /**
     * @return MemberType::ENUM_CASE
     */
    public function getMemberType(): int
    {
        return MemberType::ENUM_CASE;
    }

    public function getMemberRef(): EnumCaseRef
    {
        return $this->enumCase;
    }

    public function concretizeMixedClassNameUsage(string $className): self
    {
        if ($this->enumCase->getClassName() !== null) {
            throw new LogicException('Usage is not mixed, thus it cannot be concretized');
        }

        return new self(
            $this->getOrigin(),
            new EnumCaseRef(
                $className,
                $this->enumCase->getMemberName(),
            ),
        );
    }

}

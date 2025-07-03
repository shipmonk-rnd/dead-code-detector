<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class EnumCaseRef extends ClassMemberRef
{

    /**
     * @param bool $possibleDescendant Can be true only for maybe-enums (interfaces)
     */
    public function __construct(
        ?string $className,
        ?string $enumCaseName,
        bool $possibleDescendant
    )
    {
        parent::__construct($className, $enumCaseName, $possibleDescendant);
    }

    public static function buildKey(
        string $typeName,
        string $memberName
    ): string
    {
        return 'e/' . $typeName . '::' . $memberName;
    }

    /**
     * @return MemberType::ENUM_CASE
     */
    public function getMemberType(): int
    {
        return MemberType::ENUM_CASE;
    }

}

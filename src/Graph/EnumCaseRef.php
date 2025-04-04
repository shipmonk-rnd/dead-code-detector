<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class EnumCaseRef extends ClassMemberRef
{

    public function __construct(
        ?string $className,
        ?string $enumCaseName
    )
    {
        parent::__construct($className, $enumCaseName, false);
    }

    public static function buildKey(string $typeName, string $memberName): string
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

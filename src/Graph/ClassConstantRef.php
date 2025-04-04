<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassConstantRef extends ClassMemberRef
{

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
        return 'c/' . $typeName . '::' . $memberName;
    }

    /**
     * @return MemberType::CONSTANT
     */
    public function getMemberType(): int
    {
        return MemberType::CONSTANT;
    }

}

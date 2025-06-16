<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
final class ClassMethodRef extends ClassMemberRef
{

    /**
     * @param bool $possibleDescendant True if the $className can be a descendant of the actual class
     */
    public function __construct(
        ?string $className,
        ?string $methodName,
        bool $possibleDescendant
    )
    {
        parent::__construct($className, $methodName, $possibleDescendant);
    }

    public static function buildKey(
        string $typeName,
        string $memberName
    ): string
    {
        return 'm/' . $typeName . '::' . $memberName;
    }

    /**
     * @return MemberType::METHOD
     */
    public function getMemberType(): int
    {
        return MemberType::METHOD;
    }

}

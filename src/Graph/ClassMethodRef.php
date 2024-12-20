<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
final class ClassMethodRef extends ClassMemberRef
{

    /**
     * @param string|null $className Null if method is called over unknown type
     * @param bool $possibleDescendant True if the $className can be a descendant of the actual class
     */
    public function __construct(
        ?string $className,
        string $methodName,
        bool $possibleDescendant
    )
    {
        parent::__construct($className, $methodName, $possibleDescendant, ClassMemberRef::TYPE_METHOD);
    }

    public static function buildKey(string $typeName, string $memberName): string
    {
        return 'm/' . $typeName . '::' . $memberName;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
class ClassMethodRef extends ClassMemberRef
{

    public function __construct(
        ?string $className, // TODO nullability to * ?
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

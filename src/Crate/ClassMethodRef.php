<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class ClassMethodRef extends ClassMemberRef
{

    public function __construct(
        ?string $className,
        string $methodName
    )
    {
        parent::__construct($className, $methodName, ClassMemberRef::TYPE_METHOD);
    }

    public static function buildKey(string $typeName, string $memberName): string
    {
        return 'm/' . $typeName . '::' . $memberName;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class ClassConstantRef extends ClassMemberRef
{

    public function __construct(
        ?string $className,
        string $constantName
    )
    {
        parent::__construct($className, $constantName, ClassMemberRef::TYPE_CONSTANT);
    }

    public static function buildKey(string $typeName, string $memberName): string
    {
        return 'c/' . $typeName . '::' . $memberName;
    }

}

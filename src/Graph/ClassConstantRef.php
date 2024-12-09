<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
final class ClassConstantRef extends ClassMemberRef
{

    public function __construct(
        ?string $className,
        string $constantName,
        bool $possibleDescendant
    )
    {
        parent::__construct($className, $constantName, $possibleDescendant, ClassMemberRef::TYPE_CONSTANT);
    }

    public static function buildKey(string $typeName, string $memberName): string
    {
        return 'c/' . $typeName . '::' . $memberName;
    }

}

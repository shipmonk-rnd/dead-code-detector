<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @immutable
 */
abstract class ClassMemberRef
{

    private const UNKNOWN_CLASS = '*';

    private ?string $className;

    private ?string $memberName;

    private bool $possibleDescendant;

    /**
     * @param string|null $className Null if member is accessed over unknown type, e.g. unknown caller like $unknown->method()
     * @param string|null $memberName Null if member name is unknown, e.g. unknown method like $class->$unknown()
     */
    public function __construct(
        ?string $className,
        ?string $memberName,
        bool $possibleDescendant
    )
    {
        $this->className = $className;
        $this->memberName = $memberName;
        $this->possibleDescendant = $possibleDescendant;
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }

    public function getMemberName(): ?string
    {
        return $this->memberName;
    }

    public function isPossibleDescendant(): bool
    {
        return $this->possibleDescendant;
    }

    public function toHumanString(): string
    {
        $classRef = $this->className ?? self::UNKNOWN_CLASS;
        $memberRef = $this->memberName ?? self::UNKNOWN_CLASS;
        return $classRef . '::' . $memberRef;
    }

    public function toKey(): string
    {
        $classRef = $this->className ?? self::UNKNOWN_CLASS;
        $memberRef = $this->memberName ?? self::UNKNOWN_CLASS;
        return static::buildKey($classRef, $memberRef);
    }

    abstract public static function buildKey(
        string $typeName,
        string $memberName
    ): string;

    /**
     * @return MemberType::*
     */
    abstract public function getMemberType(): int;

}

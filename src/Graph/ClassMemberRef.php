<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

/**
 * @immutable
 */
abstract class ClassMemberRef
{

    public const TYPE_METHOD = 1;
    public const TYPE_CONSTANT = 2;

    private const UNKNOWN_CLASS = '*';

    private ?string $className;

    private string $memberName;

    private bool $possibleDescendant;

    public function __construct(
        ?string $className,
        string $memberName,
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

    public function getMemberName(): string
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
        return $classRef . '::' . $this->memberName;
    }

    public function toKey(): string
    {
        $classRef = $this->className ?? self::UNKNOWN_CLASS;
        return static::buildKey($classRef, $this->memberName);
    }

    abstract public static function buildKey(string $typeName, string $memberName): string;

}

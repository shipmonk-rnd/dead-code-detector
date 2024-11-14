<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
abstract class ClassMemberRef
{

    public const TYPE_METHOD = 1;
    public const TYPE_CONSTANT = 2;

    public const UNKNOWN_CLASS = '*';

    public ?string $className;

    public string $memberName;

    /**
     * @var self::TYPE_*
     */
    public int $memberType;

    /**
     * @param self::TYPE_* $memberType
     */
    public function __construct(
        ?string $className,
        string $memberName,
        int $memberType
    )
    {
        $this->className = $className;
        $this->memberName = $memberName;
        $this->memberType = $memberType;
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

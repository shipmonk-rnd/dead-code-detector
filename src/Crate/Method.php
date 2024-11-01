<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

/**
 * @immutable
 */
class Method
{

    public const UNKNOWN_CLASS = '*';

    public ?string $className;

    public string $methodName;

    public function __construct(
        ?string $className,
        string $methodName
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
    }

    public function toString(): string
    {
        $classRef = $this->className ?? self::UNKNOWN_CLASS;
        return $classRef . '::' . $this->methodName;
    }

}

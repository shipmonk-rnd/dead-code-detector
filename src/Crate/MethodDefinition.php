<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function count;
use function explode;

/**
 * @readonly
 */
class MethodDefinition
{

    public string $className;

    public string $methodName;

    public function __construct(
        string $className,
        string $methodName
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
    }

    public static function fromString(string $methodKey): self
    {
        $exploded = explode('::', $methodKey);

        if (count($exploded) !== 2) {
            throw new LogicException("Invalid method key: $methodKey");
        }

        [$className, $methodName] = $exploded;
        return new self($className, $methodName);
    }

    public function toString(): string
    {
        return "{$this->className}::{$this->methodName}";
    }

}

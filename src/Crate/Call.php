<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function count;
use function explode;

/**
 * @readonly
 */
class Call
{

    public string $className;

    public string $methodName;

    public bool $possibleDescendantCall;

    public function __construct(
        string $className,
        string $methodName,
        bool $possibleDescendantCall
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
        $this->possibleDescendantCall = $possibleDescendantCall;
    }

    public function getDefinition(): MethodDefinition
    {
        return new MethodDefinition($this->className, $this->methodName);
    }

    public function toString(): string
    {
        return "{$this->className}::{$this->methodName}::" . ($this->possibleDescendantCall ? '1' : '');
    }

    public static function fromString(string $methodKey): self
    {
        $exploded = explode('::', $methodKey);

        if (count($exploded) !== 3) {
            throw new LogicException("Invalid method key: $methodKey");
        }

        [$className, $methodName, $possibleDescendantCall] = $exploded;
        return new self($className, $methodName, $possibleDescendantCall === '1');
    }

}

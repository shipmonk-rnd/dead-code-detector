<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function serialize;
use function unserialize;

/**
 * @immutable
 */
class ClassMethodCall
{

    public ?ClassMethodRef $caller;

    public ClassMethodRef $callee;

    public bool $possibleDescendantCall;

    public function __construct(
        ?ClassMethodRef $caller,
        ClassMethodRef $callee,
        bool $possibleDescendantCall
    )
    {
        $this->caller = $caller;
        $this->callee = $callee;
        $this->possibleDescendantCall = $possibleDescendantCall;
    }

    public function serialize(): string
    {
        return serialize($this);
    }

    public static function deserialize(string $data): self
    {
        $result = unserialize($data);

        if (!$result instanceof self) {
            $self = self::class;
            throw new LogicException("Invalid string for $self deserialization: $data");
        }

        return $result;
    }

}

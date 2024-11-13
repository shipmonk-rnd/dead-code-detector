<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function serialize;
use function unserialize;

/**
 * @immutable
 */
class ClassConstantFetch
{

    public ?ClassMethodRef $origin; // TODO always known class, introduce new type?

    public ClassConstantRef $fetch;

    public function __construct(
        ?ClassMethodRef $origin,
        ClassConstantRef $fetch
    )
    {
        $this->origin = $origin;
        $this->fetch = $fetch;
    }

    public function toString(): string
    {
        return serialize($this);
    }

    public static function fromString(string $callKey): self
    {
        $result = unserialize($callKey, ['allowed_classes' => [self::class, ClassMethodRef::class, ClassConstantRef::class]]);

        if (!$result instanceof self) {
            $self = self::class;
            throw new LogicException("Invalid string for $self deserialization: $callKey");
        }

        return $result;
    }

}

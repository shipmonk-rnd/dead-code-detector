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

    public bool $possibleDescendantFetch;

    public function __construct(
        ?ClassMethodRef $origin,
        ClassConstantRef $fetch,
        bool $possibleDescendantFetch
    )
    {
        $this->origin = $origin;
        $this->fetch = $fetch;
        $this->possibleDescendantFetch = $possibleDescendantFetch;
    }

    public function serialize(): string
    {
        return serialize($this);
    }

    public static function deserialize(string $data): self
    {
        $result = unserialize($data, ['allowed_classes' => [self::class, ClassMethodRef::class, ClassConstantRef::class]]);

        if (!$result instanceof self) {
            $self = self::class;
            throw new LogicException("Invalid string for $self deserialization: $data");
        }

        return $result;
    }

}

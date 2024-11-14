<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function serialize;
use function unserialize;

/**
 * @immutable
 */
abstract class ClassMemberUsage
{

    /**
     * Origin method of the usage, "where it was called from"
     */
    private ?ClassMethodRef $origin;

    /**
     * If true, class of getMemberUsage() may be descendant
     */
    private bool $possibleDescendantUsage;

    public function __construct(
        ?ClassMethodRef $origin,
        bool $possibleDescendantUsage
    )
    {
        $this->origin = $origin;
        $this->possibleDescendantUsage = $possibleDescendantUsage;
    }

    public function isPossibleDescendantUsage(): bool
    {
        return $this->possibleDescendantUsage;
    }

    public function getOrigin(): ?ClassMethodRef
    {
        return $this->origin;
    }

    /**
     * @return ClassMemberRef::TYPE_*
     */
    abstract public function getMemberType(): int;

    abstract public function getMemberUsage(): ClassMemberRef;

    public function serialize(): string
    {
        return serialize($this);
    }

    /**
     * @return static
     */
    public static function deserialize(string $data): self
    {
        $result = unserialize($data);

        if (!$result instanceof static) {
            $self = static::class;
            throw new LogicException("Invalid string for $self deserialization: $data");
        }

        return $result;
    }

}

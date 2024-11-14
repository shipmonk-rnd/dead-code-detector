<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Crate;

use LogicException;
use function serialize;
use function unserialize;

/**
 * @immutable
 */
abstract class ClassMemberUse // TODO rename to ClassMemberUsage ?
{

    /**
     * Origin method of the use, "where it was called from"
     */
    private ?ClassMethodRef $caller;

    /**
     * If true, class of getMemberUse() may be descendant
     */
    private bool $possibleDescendantUse;

    public function __construct(
        ?ClassMethodRef $caller,
        bool $possibleDescendantUse
    )
    {
        $this->caller = $caller;
        $this->possibleDescendantUse = $possibleDescendantUse;
    }

    public function isPossibleDescendantUse(): bool
    {
        return $this->possibleDescendantUse;
    }

    public function getCaller(): ?ClassMethodRef
    {
        return $this->caller;
    }

    /**
     * @return ClassMemberRef::TYPE_*
     */
    abstract public function getMemberType(): int;

    abstract public function getMemberUse(): ClassMemberRef;

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

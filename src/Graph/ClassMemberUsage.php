<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

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

    public function __construct(?ClassMethodRef $origin)
    {
        if ($origin !== null && $origin->isPossibleDescendant()) {
            throw new LogicException('Origin should always be exact place in codebase.');
        }

        if ($origin !== null && $origin->getClassName() === null) {
            throw new LogicException('Origin should always be exact place in codebase, thus className should be known.');
        }

        $this->origin = $origin;
    }

    public function getOrigin(): ?ClassMethodRef
    {
        return $this->origin;
    }

    /**
     * @return ClassMemberRef::TYPE_*
     */
    abstract public function getMemberType(): int;

    abstract public function getMemberRef(): ClassMemberRef;

    public function toHumanString(): string
    {
        $origin = $this->origin !== null ? $this->origin->toHumanString() : 'unknown';
        $callee = $this->getMemberRef()->toHumanString();

        return "$origin -> $callee";
    }

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

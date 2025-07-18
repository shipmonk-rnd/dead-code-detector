<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @template-covariant C of string|null
 * @template-covariant M of string|null
 */
abstract class ClassMemberRef
{

    private const UNKNOWN_CLASS = '*';

    /**
     * @var C
     */
    private ?string $className;

    /**
     * @var M
     */
    private ?string $memberName;

    private bool $possibleDescendant;

    /**
     * @param C $className Null if member is accessed over unknown type, e.g. unknown caller like $unknown->method()
     * @param M $memberName Null if member name is unknown, e.g. unknown method like $class->$unknown()
     */
    public function __construct(
        ?string $className,
        ?string $memberName,
        bool $possibleDescendant
    )
    {
        $this->className = $className;
        $this->memberName = $memberName;
        $this->possibleDescendant = $possibleDescendant;
    }

    /**
     * @return C
     */
    public function getClassName(): ?string
    {
        return $this->className;
    }

    /**
     * @return M
     */
    public function getMemberName(): ?string
    {
        return $this->memberName;
    }

    public function isPossibleDescendant(): bool
    {
        return $this->possibleDescendant;
    }

    public function toHumanString(): string
    {
        $classRef = $this->className ?? self::UNKNOWN_CLASS;
        $memberRef = $this->memberName ?? self::UNKNOWN_CLASS;
        return $classRef . '::' . $memberRef;
    }

    /**
     * @return list<string>
     */
    public function toKeys(): array
    {
        if ($this->className === null) {
            throw new LogicException('Cannot convert to keys without known class name.');
        }

        if ($this->memberName === null) {
            throw new LogicException('Cannot convert to keys without known member name.');
        }

        $result = [];
        foreach ($this->getKeyPrefixes() as $prefix) {
            $result[] = "$prefix/$this->className::$this->memberName";
        }
        return $result;
    }

    /**
     * @phpstan-assert-if-true self<string, M> $this
     */
    public function hasKnownClass(): bool
    {
        return $this->className !== null;
    }

    /**
     * @phpstan-assert-if-true self<C, string> $this
     */
    public function hasKnownMember(): bool
    {
        return $this->memberName !== null;
    }

    /**
     * @return static<string, string>
     */
    abstract public function withKnownNames(
        string $className,
        string $memberName
    ): self;

    /**
     * @return static<string, M>
     */
    abstract public function withKnownClass(string $className): self;

    /**
     * @return static<C, string>
     */
    abstract public function withKnownMember(string $memberName): self;

    /**
     * @return list<string>
     */
    abstract protected function getKeyPrefixes(): array;

    /**
     * @return MemberType::*
     */
    abstract public function getMemberType(): int;

}

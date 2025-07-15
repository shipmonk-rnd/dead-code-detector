<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @template-covariant C of string|null
 * @template-covariant M of string|null
 * @template-extends ClassMemberRef<C, M>
 */
final class ClassMethodRef extends ClassMemberRef
{

    /**
     * @param C $className
     * @param M $methodName
     * @param bool $possibleDescendant True if the $className can be a descendant of the actual class
     */
    public function __construct(
        ?string $className,
        ?string $methodName,
        bool $possibleDescendant
    )
    {
        parent::__construct($className, $methodName, $possibleDescendant);
    }

    /**
     * @return list<string>
     */
    protected function getKeyPrefixes(): array
    {
        return ['m'];
    }

    /**
     * @return MemberType::METHOD
     */
    public function getMemberType(): int
    {
        return MemberType::METHOD;
    }

    public function withKnownNames(
        string $className,
        string $memberName
    ): self
    {
        return new self(
            $className,
            $memberName,
            $this->isPossibleDescendant(),
        );
    }

    public function withKnownClass(string $className): self
    {
        return new self(
            $className,
            $this->getMemberName(),
            $this->isPossibleDescendant(),
        );
    }

    public function withKnownMember(string $memberName): self
    {
        return new self(
            $this->getClassName(),
            $memberName,
            $this->isPossibleDescendant(),
        );
    }

}

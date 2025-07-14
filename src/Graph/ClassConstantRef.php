<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PHPStan\TrinaryLogic;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;

/**
 * @template-covariant C of string|null
 * @template-covariant M of string|null
 * @template-extends ClassMemberRef<C, M>
 */
final class ClassConstantRef extends ClassMemberRef
{

    private TrinaryLogic $isEnumCase;

    /**
     * @param C $className
     * @param M $constantName
     */
    public function __construct(
        ?string $className,
        ?string $constantName,
        bool $possibleDescendant,
        TrinaryLogic $isEnumCase
    )
    {
        parent::__construct($className, $constantName, $possibleDescendant);

        $this->isEnumCase = $isEnumCase;
    }

    protected function buildKeys(
        string $typeName,
        string $memberName
    ): array
    {
        if ($this->isEnumCase->maybe()) {
            return [
                'c/' . $typeName . '::' . $memberName,
                'e/' . $typeName . '::' . $memberName,
            ];
        } elseif ($this->isEnumCase->yes()) {
            return ['e/' . $typeName . '::' . $memberName];

        } else {
            return ['c/' . $typeName . '::' . $memberName];
        }
    }

    /**
     * @return MemberType::CONSTANT
     */
    public function getMemberType(): int
    {
        return MemberType::CONSTANT;
    }

    public function isEnumCase(): TrinaryLogic
    {
        return $this->isEnumCase;
    }

    public function withKnownNames(
        string $className,
        string $memberName
    ): ClassMemberRef
    {
        return new self(
            $className,
            $memberName,
            $this->isPossibleDescendant(),
            $this->isEnumCase,
        );
    }

    public function withKnownClass(string $className): ClassMemberRef
    {
        return new self(
            $className,
            $this->getMemberName(),
            $this->isPossibleDescendant(),
            $this->isEnumCase,
        );
    }

    public function withKnownMember(string $memberName): ClassMemberRef
    {
        return new self(
            $this->getClassName(),
            $memberName,
            $this->isPossibleDescendant(),
            $this->isEnumCase,
        );
    }

}

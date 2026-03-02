<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * @immutable
 */
final class UsageOrigin
{

    /**
     * @param ?string $memberName Origin method or property name
     * @param ?MemberType $memberType Origins in method or property hook?
     * @param ?AccessType $accessType Is it get hook or set hook?
     *
     * @internal Please use static constructors instead.
     */
    public function __construct(
        private readonly ?string $className,
        private readonly ?string $memberName,
        private readonly ?MemberType $memberType,
        private readonly ?AccessType $accessType,
        private readonly ?string $fileName,
        private readonly ?int $line,
        private readonly ?string $provider,
        private readonly ?string $note,
    )
    {
    }

    /**
     * Creates virtual usage origin with no reference to any place in code
     */
    public static function createVirtual(
        MemberUsageProvider $provider,
        VirtualUsageData $data,
    ): self
    {
        return new self(
            className: null,
            memberName: null,
            memberType: null,
            accessType: null,
            fileName: null,
            line: null,
            provider: $provider::class,
            note: $data->getNote(),
        );
    }

    /**
     * Creates usage origin with reference to file:line
     */
    public static function createRegular(
        Node $node,
        Scope $scope,
    ): self
    {
        $file = $scope->isInTrait()
            ? $scope->getTraitReflection()->getFileName()
            : $scope->getFile();

        $function = $scope->getFunction();
        $isMethodOrHook = $function !== null && $function->isMethodOrPropertyHook();

        if (!$scope->isInClass() || !$isMethodOrHook) {
            return new self(
                className: null,
                memberName: null,
                memberType: null,
                accessType: null,
                fileName: $file,
                line: $node->getStartLine(),
                provider: null,
                note: null,
            );
        }

        $hookedPropertyName = $function->getHookedPropertyName();

        return new self(
            $scope->getClassReflection()->getName(),
            $hookedPropertyName ?? $function->getName(),
            $function->isPropertyHook() ? MemberType::PROPERTY : MemberType::METHOD,
            $function->isPropertyHook() && $function->getPropertyHookName() === 'set' ? AccessType::WRITE : AccessType::READ,
            $file,
            $node->getStartLine(),
            null,
            null,
        );
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }

    public function getMemberName(): ?string
    {
        return $this->memberName;
    }

    public function getMemberType(): ?MemberType
    {
        return $this->memberType;
    }

    public function getAccessType(): ?AccessType
    {
        return $this->accessType;
    }

    public function getFile(): ?string
    {
        return $this->fileName;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @phpstan-assert-if-true !null $this->getAccessType()
     */
    public function hasClassMemberRef(): bool
    {
        return $this->className !== null && $this->memberName !== null;
    }

    /**
     * @return ClassMemberRef<string, string>
     */
    public function toClassMemberRef(): ClassMemberRef
    {
        if ($this->className === null || $this->memberName === null) {
            throw new LogicException('Usage origin does not have class method ref');
        }

        if ($this->memberType === MemberType::PROPERTY) {
            return new ClassPropertyRef(
                $this->className,
                $this->memberName,
                possibleDescendant: false,
            );
        }

        return new ClassMethodRef(
            $this->className,
            $this->memberName,
            possibleDescendant: false,
        );
    }

}

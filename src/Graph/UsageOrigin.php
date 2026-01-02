<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use function get_class;

/**
 * @immutable
 */
final class UsageOrigin
{

    private ?string $className;

    /**
     * Origin method or property name
     */
    private ?string $memberName;

    /**
     * Origins in method or property hook?
     *
     * @var MemberType::PROPERTY|MemberType::METHOD|null
     */
    private ?int $memberType;

    /**
     * Is it get hook or set hook?
     *
     * @var AccessType::*|null
     */
    private ?int $accessType;

    private ?string $fileName;

    private ?int $line;

    private ?string $provider;

    private ?string $note;

    /**
     * @param MemberType::PROPERTY|MemberType::METHOD|null $memberType
     * @param AccessType::*|null $accessType
     *
     * @internal Please use static constructors instead.
     */
    public function __construct(
        ?string $className,
        ?string $memberName,
        ?int $memberType,
        ?int $accessType,
        ?string $fileName,
        ?int $line,
        ?string $provider,
        ?string $note
    )
    {
        $this->className = $className;
        $this->memberName = $memberName;
        $this->memberType = $memberType;
        $this->accessType = $accessType;
        $this->fileName = $fileName;
        $this->line = $line;
        $this->provider = $provider;
        $this->note = $note;
    }

    /**
     * Creates virtual usage origin with no reference to any place in code
     */
    public static function createVirtual(
        MemberUsageProvider $provider,
        VirtualUsageData $data
    ): self
    {
        return new self(
            null,
            null,
            null,
            null,
            null,
            null,
            get_class($provider),
            $data->getNote(),
        );
    }

    /**
     * Creates usage origin with reference to file:line
     */
    public static function createRegular(
        Node $node,
        Scope $scope
    ): self
    {
        $file = $scope->isInTrait()
            ? $scope->getTraitReflection()->getFileName()
            : $scope->getFile();

        $function = $scope->getFunction();
        $isMethodOrHook = $function !== null && $function->isMethodOrPropertyHook();

        if (!$scope->isInClass() || !$isMethodOrHook) {
            return new self(
                null,
                null,
                null,
                null,
                $file,
                $node->getStartLine(),
                null,
                null,
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

    /**
     * @return MemberType::PROPERTY|MemberType::METHOD|null
     */
    public function getMemberType(): ?int
    {
        return $this->memberType;
    }

    /**
     * @return AccessType::*|null
     */
    public function getAccessType(): ?int
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
                false,
            );
        }

        return new ClassMethodRef(
            $this->className,
            $this->memberName,
            false,
        );
    }

}

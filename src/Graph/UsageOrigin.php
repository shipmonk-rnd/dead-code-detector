<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use function get_class;

/**
 * @immutable
 */
final class UsageOrigin
{

    private ?string $className;

    private ?string $methodName;

    private ?string $fileName;

    private ?int $line;

    private ?string $provider;

    private ?string $note;

    /**
     * @internal Please use static constructors instead.
     */
    public function __construct(
        ?string $className,
        ?string $methodName,
        ?string $fileName, // TODO try reusing collector file to reduce memory usage
        ?int $line,
        ?string $provider,
        ?string $note
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
        $this->fileName = $fileName;
        $this->line = $line;
        $this->provider = $provider;
        $this->note = $note;
    }

    /**
     * Creates virtual usage origin with no reference to any place in code
     *
     * @param ?string $note More detailed identification why provider emitted this virtual usage
     */
    public static function createVirtual(MemberUsageProvider $provider, ?string $note = null): self
    {
        return new self(
            null,
            null,
            null,
            null,
            get_class($provider),
            $note,
        );
    }

    /**
     * Creates usage origin with reference to file:line
     */
    public static function createRegular(Node $node, Scope $scope): self
    {
        if (!$scope->isInClass() || !$scope->getFunction() instanceof MethodReflection) {
            return new self(
                null,
                null,
                $scope->getFile(),
                $node->getStartLine(),
                null,
                null,
            );
        }

        return new self(
            $scope->getClassReflection()->getName(),
            $scope->getFunction()->getName(),
            $scope->getFile(),
            $node->getStartLine(),
            null,
            null,
        );
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }

    public function getMethodName(): ?string
    {
        return $this->methodName;
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

    public function hasClassMethodRef(): bool
    {
        return $this->className !== null && $this->methodName !== null;
    }

    public function toClassMethodRef(): ClassMethodRef
    {
        if ($this->className === null || $this->methodName === null) {
            throw new LogicException('Usage origin does not have class method ref');
        }

        return new ClassMethodRef(
            $this->className,
            $this->methodName,
            false,
        );
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * @immutable
 */
final class UsageOrigin
{

    private ?string $className;

    private ?string $methodName;

    private ?string $fileName;

    private ?int $line;

    private ?string $reason;

    public function __construct( // TODO private?
        ?string $className,
        ?string $methodName,
        ?string $fileName,
        ?int $line,
        ?string $reason = null
    )
    {
        $this->className = $className;
        $this->methodName = $methodName;
        $this->fileName = $fileName;
        $this->line = $line;
        $this->reason = $reason;
    }

    /**
     * @param class-string<MemberUsageProvider> $providerClass
     * @param ?string $reason More detailed identification why provider emitted this usage
     */
    public static function fromProvider(string $providerClass, ?string $reason): self
    {
        return new self(
            null,
            null,
            null,
            null,
            $providerClass . ' - ' . (string) $reason, // TODO better approach?
        );
    }

    public static function fromScope(Node $node, Scope $scope): self
    {
        if (!$scope->isInClass() || !$scope->getFunction() instanceof MethodReflection) {
            return new self(
                null,
                null,
                $scope->getFile(),
                $node->getStartLine(),
            );
        }

        return new self(
            $scope->getClassReflection()->getName(),
            $scope->getFunction()->getName(),
            $scope->getFile(),
            $node->getStartLine(),
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

    public function getReason(): ?string
    {
        return $this->reason;
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

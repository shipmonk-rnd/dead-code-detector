<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use function get_class;
use function sprintf;

/**
 * @implements Collector<Node, list<string>>
 */
class ProvidedUsagesCollector implements Collector
{

    use BufferedUsageCollector;

    private ReflectionProvider $reflectionProvider;

    /**
     * @var list<MemberUsageProvider>
     */
    private array $memberUsageProviders;

    /**
     * @param list<MemberUsageProvider> $memberUsageProviders
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        array $memberUsageProviders
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->memberUsageProviders = $memberUsageProviders;
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return non-empty-list<string>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        foreach ($this->memberUsageProviders as $memberUsageProvider) {
            $newUsages = $memberUsageProvider->getUsages($node, $scope);

            foreach ($newUsages as $newUsage) {
                $this->validateUsage($newUsage, $memberUsageProvider, $node, $scope);
                $this->usageBuffer[] = $newUsage;
            }
        }

        return $this->tryFlushBuffer($node, $scope);
    }

    private function validateUsage(
        ClassMemberUsage $usage,
        MemberUsageProvider $provider,
        Node $node,
        Scope $scope
    ): void
    {
        $memberRef = $usage->getMemberRef();
        $memberRefClass = $memberRef->getClassName();

        $originRef = $usage->getOrigin();
        $originRefClass = $originRef === null ? null : $originRef->getClassName();

        $context = sprintf(
            "It was emitted as %s by %s for node '%s' in '%s' on line %s",
            $usage->toHumanString(),
            get_class($provider),
            get_class($node),
            $scope->getFile(),
            $node->getStartLine(),
        );

        if (($memberRefClass !== null) && !$this->reflectionProvider->hasClass($memberRefClass)) {
            throw new LogicException("Class '$memberRefClass' does not exist. $context");
        }

        if (
            $originRef !== null
            && $originRefClass !== null
        ) {
            if (!$this->reflectionProvider->hasClass($originRefClass)) {
                throw new LogicException("Class '{$originRefClass}' does not exist. $context");
            }

            if (!$this->reflectionProvider->getClass($originRefClass)->hasMethod($originRef->getMemberName())) {
                throw new LogicException("Method '{$originRef->getMemberName()}' does not exist in class '$originRefClass'. $context");
            }
        }
    }

}

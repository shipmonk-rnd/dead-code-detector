<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
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
     * @var list<MemberUsageExcluder>
     */
    private array $memberUsageExcluders;

    /**
     * @param list<MemberUsageProvider> $memberUsageProviders
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        array $memberUsageProviders,
        array $memberUsageExcluders
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->memberUsageProviders = $memberUsageProviders;
        $this->memberUsageExcluders = $memberUsageExcluders;
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
                $collectedUsage = $this->resolveExclusion($newUsage, $node, $scope);

                $this->validateUsage($newUsage, $memberUsageProvider, $node, $scope);
                $this->usages[] = $collectedUsage;
            }
        }

        return $this->emitUsages($scope);
    }

    private function validateUsage(
        ClassMemberUsage $usage,
        MemberUsageProvider $provider,
        Node $node,
        Scope $scope
    ): void
    {
        $origin = $usage->getOrigin();
        $originClass = $origin->getClassName();
        $originMethod = $origin->getMethodName();

        $context = sprintf(
            "It emitted usage of %s by %s for node '%s' in '%s' on line %s",
            $usage->getMemberRef()->toHumanString(),
            get_class($provider),
            get_class($node),
            $scope->getFile(),
            $node->getStartLine(),
        );

        if ($originClass !== null) {
            if (!$this->reflectionProvider->hasClass($originClass)) {
                throw new LogicException("Class '{$originClass}' does not exist. $context");
            }

            if ($originMethod !== null && !$this->reflectionProvider->getClass($originClass)->hasMethod($originMethod)) {
                throw new LogicException("Method '{$originMethod}' does not exist in class '$originClass'. $context");
            }
        }
    }

    private function resolveExclusion(
        ClassMemberUsage $usage,
        Node $node,
        Scope $scope
    ): CollectedUsage
    {
        $excluderName = null;

        foreach ($this->memberUsageExcluders as $excludedUsageDecider) {
            if ($excludedUsageDecider->shouldExclude($usage, $node, $scope)) {
                $excluderName = $excludedUsageDecider->getIdentifier();
                break;
            }
        }

        return new CollectedUsage($usage, $excluderName);
    }

}

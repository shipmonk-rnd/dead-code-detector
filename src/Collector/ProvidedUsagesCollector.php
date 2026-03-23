<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use function sprintf;

/**
 * @implements Collector<Node, list<string>>
 */
final class ProvidedUsagesCollector implements Collector
{

    use BufferedUsageCollector;

    /**
     * @param list<MemberUsageProvider> $memberUsageProviders
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $memberUsageProviders,
        private readonly array $memberUsageExcluders,
    )
    {
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
        Scope $scope,
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
        Scope $scope,
    ): void
    {
        $origin = $usage->getOrigin();
        $originClass = $origin->getClassName();
        $originMember = $origin->getMemberName();

        $context = sprintf(
            "It emitted usage of %s by %s for node '%s' in '%s' on line %s",
            $usage->getMemberRef()->toHumanString(),
            $provider::class,
            $node::class,
            $scope->getFile(),
            $node->getStartLine(),
        );

        if ($originClass !== null) {
            if (!$this->reflectionProvider->hasClass($originClass)) {
                throw new LogicException("Class '{$originClass}' does not exist. $context");
            }

            if ($originMember !== null) {
                if ($origin->getMemberType() === MemberType::METHOD && !$this->reflectionProvider->getClass($originClass)->hasMethod($originMember)) {
                    throw new LogicException("Method '{$originMember}' does not exist in class '$originClass'. $context");
                }

                if ($origin->getMemberType() === MemberType::PROPERTY && !$this->reflectionProvider->getClass($originClass)->hasNativeProperty($originMember)) {
                    throw new LogicException("Property '{$originMember}' does not exist in class '$originClass'. $context");
                }
            }
        }
    }

    private function resolveExclusion(
        ClassMemberUsage $usage,
        Node $node,
        Scope $scope,
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

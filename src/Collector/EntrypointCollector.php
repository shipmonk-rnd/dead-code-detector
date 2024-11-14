<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Provider\MethodEntrypointProvider;

/**
 * @implements Collector<InClassNode, list<string>>
 */
class EntrypointCollector implements Collector
{

    /**
     * @var list<MethodEntrypointProvider>
     */
    private array $entrypointProviders;

    /**
     * @param list<MethodEntrypointProvider> $entrypointProviders
     */
    public function __construct(
        array $entrypointProviders
    )
    {
        $this->entrypointProviders = $entrypointProviders;
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return non-empty-list<string>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        $entrypoints = [];

        foreach ($this->entrypointProviders as $entrypointProvider) {
            foreach ($entrypointProvider->getEntrypoints($node->getClassReflection()) as $entrypointMethod) {
                $call = new ClassMethodUsage(
                    null,
                    new ClassMethodRef(
                        $entrypointMethod->getDeclaringClass()->getName(),
                        $entrypointMethod->getName(),
                        false,
                    ),
                );
                $entrypoints[] = $call->serialize();
            }
        }

        return $entrypoints === [] ? null : $entrypoints;
    }

}

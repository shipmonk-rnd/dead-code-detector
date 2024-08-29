<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;

/**
 * @implements Collector<InClassNode, array<string, string>>
 */
class ClassDefinitionCollector implements Collector
{

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return array<string, string>
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): array
    {
        $pairs = [];
        $origin = $node->getClassReflection();

        foreach ($origin->getAncestors() as $ancestor) {
            if ($ancestor->isTrait() || $ancestor === $origin) {
                continue;
            }

            $pairs[$ancestor->getName()] = $origin->getName();
        }

        return $pairs;
    }

}

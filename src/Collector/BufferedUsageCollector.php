<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;
use ShipMonk\PHPStan\DeadCode\Cache\UsageCacheStorage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;

/**
 * @phpstan-require-implements Collector
 */
trait BufferedUsageCollector
{

    /**
     * @var list<CollectedUsage>
     */
    private array $usages = [];

    private UsageCacheStorage $usageCacheStorage;

    /**
     * @return non-empty-list<string>|null
     */
    private function tryFlushBuffer(
        Node $node,
        Scope $scope,
    ): ?array
    {
        if ($this->usages !== [] && (!$scope->isInClass() || $node instanceof ClassMethodsNode)) { // @phpstan-ignore phpstanApi.instanceofAssumption
            try {
                $hash = $this->usageCacheStorage->write($this->usages, $scope->getFile());

                return [$hash];
            } finally {
                $this->usages = [];
            }
        }

        return null;
    }

}

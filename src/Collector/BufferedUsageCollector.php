<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;

/**
 * @phpstan-require-implements Collector
 */
trait BufferedUsageCollector
{

    /**
     * @var list<CollectedUsage>
     */
    private array $usages = [];

    /**
     * @return non-empty-list<string>|null
     */
    private function emitUsages(Scope $scope): ?array
    {
        try {
            return $this->usages === []
                ? null
                : array_map(
                    static fn (CollectedUsage $usage): string => $usage->serialize($scope->getFile()),
                    $this->usages,
                );
        } finally {
            $this->usages = [];
        }
    }

}

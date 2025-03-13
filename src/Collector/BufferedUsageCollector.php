<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use function array_map;

trait BufferedUsageCollector
{

    /**
     * @var list<CollectedUsage>
     */
    private array $usages = [];

    /**
     * @return non-empty-list<string>|null
     */
    private function emitUsages(): ?array
    {
        try {
            return $this->usages === []
                ? null
                : array_map(
                    static fn(CollectedUsage $usage): string => $usage->serialize(),
                    $this->usages,
                );
        } finally {
            $this->usages = [];
        }
    }

}

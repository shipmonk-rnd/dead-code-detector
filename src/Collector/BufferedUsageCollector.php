<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassMethodsNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use function array_map;

trait BufferedUsageCollector
{

    /**
     * @var list<ClassMemberUsage>
     */
    private array $usageBuffer = [];

    /**
     * @return non-empty-list<string>|null
     */
    private function tryFlushBuffer(
        Node $node,
        Scope $scope
    ): ?array
    {
        if (!$scope->isInClass() || $node instanceof ClassMethodsNode) { // @phpstan-ignore-line ignore BC promise
            $data = $this->usageBuffer;
            $this->usageBuffer = [];

            // collect data once per class to save memory & resultCache size
            return $data === []
                ? null
                : array_map(
                    static fn (ClassMemberUsage $call): string => $call->serialize(),
                    $data,
                );
        }

        return null;
    }

}

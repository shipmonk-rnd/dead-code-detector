<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Provider\ShadowMethodCallProvider;
use function array_map;
use function array_merge;

/**
 * @implements Collector<Node, list<string>>
 */
class ShadowCallCollector implements Collector
{

    /**
     * @var list<Call>
     */
    private array $callsBuffer = [];

    /**
     * @var list<ShadowMethodCallProvider>
     */
    private array $shadowCallProviders;

    /**
     * @param list<ShadowMethodCallProvider> $shadowCallProviders
     */
    public function __construct(array $shadowCallProviders)
    {
        $this->shadowCallProviders = $shadowCallProviders;
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
        foreach ($this->shadowCallProviders as $shadowCallProvider) {
            $this->callsBuffer = array_merge(
                $this->callsBuffer,
                $shadowCallProvider->getShadowMethodCalls($node, $scope),
            );
        }

        if (!$scope->isInClass() || $node instanceof ClassMethodsNode) { // @phpstan-ignore-line ignore BC promise
            $data = $this->callsBuffer;
            $this->callsBuffer = [];

            // collect data once per class to save memory & resultCache size
            return $data === []
                ? null
                : array_map(
                    static fn (Call $call): string => $call->toString(),
                    $data,
                );
        }

        return null;
    }

}

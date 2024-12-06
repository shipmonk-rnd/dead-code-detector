<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Provider\ConstantUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\MethodUsageProvider;

/**
 * @implements Collector<InClassNode, list<string>>
 */
class ProvidedUsagesCollector implements Collector
{

    /**
     * @var list<MethodUsageProvider>
     */
    private array $methodUsageProviders;

    /**
     * @var list<ConstantUsageProvider>
     */
    private array $constantUsageProviders;

    /**
     * @param list<MethodUsageProvider> $methodUsageProviders
     * @param list<ConstantUsageProvider> $constantUsageProviders
     */
    public function __construct(
        array $methodUsageProviders,
        array $constantUsageProviders
    )
    {
        $this->methodUsageProviders = $methodUsageProviders;
        $this->constantUsageProviders = $constantUsageProviders;
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
        $usages = [];

        foreach ($this->methodUsageProviders as $methodUsageProvider) {
            foreach ($methodUsageProvider->getMethodUsages($node->getClassReflection()) as $usedMethod) {
                $methodUsage = new ClassMethodUsage(
                    null,
                    new ClassMethodRef(
                        $usedMethod->getDeclaringClass()->getName(),
                        $usedMethod->getName(),
                        false,
                    ),
                );
                $usages[] = $methodUsage->serialize();
            }
        }

        foreach ($this->constantUsageProviders as $constantUsageProvider) {
            foreach ($constantUsageProvider->getConstantUsages($node->getClassReflection()) as $usedConstant) {
                $constantUsage = new ClassConstantUsage(
                    null,
                    new ClassConstantRef(
                        $usedConstant->getDeclaringClass()->getName(),
                        $usedConstant->getName(),
                        false,
                    ),
                );
                $usages[] = $constantUsage->serialize();
            }
        }

        return $usages === [] ? null : $usages;
    }

}

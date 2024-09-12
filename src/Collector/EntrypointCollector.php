<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\ReflectionMethod as BetterReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\MethodReflection;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;

/**
 * @implements Collector<InClassNode, list<string>>
 */
class EntrypointCollector implements Collector
{

    /**
     * @var list<EntrypointProvider>
     */
    private array $entrypointProviders;

    /**
     * @param list<EntrypointProvider> $entrypointProviders
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
     * @return list<string>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        $entrypoints = [];

        foreach ($this->entrypointProviders as $entrypointProvider) {
            foreach ($entrypointProvider->getEntrypoints($node->getClassReflection()) as $entrypointMethod) {
                $entrypoints[] = $this->getRealDeclaringMethodKey($entrypointMethod);
            }
        }

        return $entrypoints === [] ? null : $entrypoints;
    }

    private function getRealDeclaringMethodKey(
        MethodReflection $methodReflection
    ): string
    {
        // @phpstan-ignore missingType.checkedException (method should always exist)
        $nativeReflectionMethod = $methodReflection->getDeclaringClass()->getNativeReflection()->getMethod($methodReflection->getName());
        $betterReflectionMethod = $nativeReflectionMethod->getBetterReflection();
        $realDeclaringClass = $betterReflectionMethod->getDeclaringClass();

        // when trait method name is aliased, we need the original name
        $realName = Closure::bind(function (): string {
            return $this->name;
        }, $betterReflectionMethod, BetterReflectionMethod::class)();

        return $realDeclaringClass->getName() . "::$realName";
    }

}

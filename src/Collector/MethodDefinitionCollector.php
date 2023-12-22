<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;
use function strpos;

/**
 * @implements Collector<InClassNode, list<array{string, int}>>
 */
class MethodDefinitionCollector implements Collector
{

    /**
     * @var array<EntrypointProvider>
     */
    private array $entrypointProviders;

    /**
     * @param array<EntrypointProvider> $entrypointProviders
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
     * @return list<array{string, int}>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        $reflection = $node->getClassReflection();
        $nativeReflection = $reflection->getNativeReflection();
        $result = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->isDestructor()) {
                continue;
            }

            if ($method->isConstructor()) {
                continue;
            }

            if (strpos($method->getName(), '__') === 0) { // magic methods like __toString, __clone, __get, __set etc
                continue;
            }

            if ($method->getFileName() === false) { // e.g. php core
                continue;
            }

            if ($scope->getFile() !== $method->getDeclaringClass()->getFileName()) { // method in parent class
                continue;
            }

            if (strpos($method->getFileName(), '/vendor/') !== false) {
                continue;
            }

            if ($this->isEntrypoint($method)) {
                continue;
            }

            $line = $method->getStartLine();

            if ($line === false) {
                continue;
            }

            $methodKey = DeadCodeHelper::composeMethodKey($method->getDeclaringClass()->getName(), $method->getName());
            $result[] = [$methodKey, $line];
        }

        return $result !== [] ? $result : null;
    }

    private function isEntrypoint(ReflectionMethod $reflectionMethod): bool
    {
        foreach ($this->entrypointProviders as $entrypointProvider) {
            if ($entrypointProvider->isEntrypoint($reflectionMethod)) {
                return true;
            }
        }

        return false;
    }

}

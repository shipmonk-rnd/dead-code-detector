<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;
use function strpos;

/**
 * @implements Collector<InClassNode, list<array{string, int}>>
 */
class MethodDefinitionCollector implements Collector
{

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

            if (!$method->isConstructor() && strpos($method->getName(), '__') === 0) { // magic methods like __toString, __clone, __get, __set etc
                continue;
            }

            if ($method->isConstructor() && $method->isPrivate()) { // e.g. classes used for storing static methods only
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

            $line = $method->getStartLine();

            if ($line === false) {
                continue;
            }

            $methodKey = DeadCodeHelper::composeMethodKey($method->getDeclaringClass()->getName(), $method->getName());
            $result[] = [$methodKey, $line];
        }

        return $result !== [] ? $result : null;
    }

}

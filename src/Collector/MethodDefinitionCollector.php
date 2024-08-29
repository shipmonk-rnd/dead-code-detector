<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;
use function strpos;

/**
 * @implements Collector<InClassNode, list<array{line: int, methodKey: string, overrides: array<string, string>, traitOrigin: ?string}>>
 */
class MethodDefinitionCollector implements Collector
{

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return list<array{line: int, methodKey: string, overrides: array<string, string>, traitOrigin: ?string}>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        $reflection = $node->getClassReflection();
        $nativeReflection = $reflection->getNativeReflection();
        $result = [];

        if ($reflection->isAnonymous()) {
            return null; // https://github.com/phpstan/phpstan/issues/8410
        }

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

            $className = $method->getDeclaringClass()->getName();
            $methodName = $method->getName();
            $methodKey = DeadCodeHelper::composeMethodKey($className, $methodName);

            $declaringTraitMethodKey = DeadCodeHelper::getDeclaringTraitMethodKey($reflection, $methodName);

            $methodOverrides = [];

            foreach ($reflection->getAncestors() as $ancestor) {
                if ($ancestor === $reflection) {
                    continue;
                }

                if (!$ancestor->hasMethod($methodName)) {
                    continue;
                }

                if ($ancestor->isTrait()) {
                    continue;
                }

                $ancestorMethodKey = DeadCodeHelper::composeMethodKey($ancestor->getName(), $methodName);
                $methodOverrides[$ancestorMethodKey] = $methodKey;
            }

            $result[] = [
                'line' => $line,
                'methodKey' => $methodKey,
                'overrides' => $methodOverrides,
                'traitOrigin' => $declaringTraitMethodKey,
            ];
        }

        return $result !== [] ? $result : null;
    }

}

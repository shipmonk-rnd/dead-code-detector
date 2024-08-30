<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use ReflectionException;
use ShipMonk\PHPStan\DeadCode\Crate\MethodDefinition;
use function array_map;
use function strpos;

/**
 * @implements Collector<InClassNode, list<array{line: int, definition: string, overriddenDefinitions: list<string>, traitOriginDefinition: ?string}>>
 */
class MethodDefinitionCollector implements Collector
{

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return list<array{line: int, definition: string, overriddenDefinitions: list<string>, traitOriginDefinition: ?string}>|null
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
            $definition = new MethodDefinition($className, $methodName);

            $declaringTraitDefinition = $this->getDeclaringTraitDefinition($reflection, $methodName);

            $overriddenDefinitions = [];

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

                $overriddenDefinitions[] = new MethodDefinition($ancestor->getName(), $methodName);
            }

            $result[] = [
                'line' => $line,
                'definition' => $definition->toString(),
                'overriddenDefinitions' => array_map(static fn (MethodDefinition $definition) => $definition->toString(), $overriddenDefinitions),
                'traitOriginDefinition' => $declaringTraitDefinition !== null ? $declaringTraitDefinition->toString() : null,
            ];
        }

        return $result !== [] ? $result : null;
    }

    private function getDeclaringTraitDefinition(
        ClassReflection $classReflection,
        string $methodName
    ): ?MethodDefinition
    {
        try {
            $realDeclaringClass = $classReflection->getNativeReflection()
                ->getMethod($methodName)
                ->getBetterReflection()
                ->getDeclaringClass();
        } catch (ReflectionException $e) {
            return null;
        }

        if ($realDeclaringClass->isTrait()) {
            return new MethodDefinition($realDeclaringClass->getName(), $methodName);
        }

        return null;
    }

}

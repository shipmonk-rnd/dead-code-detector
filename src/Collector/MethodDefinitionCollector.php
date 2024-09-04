<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\ReflectionMethod as BetterReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use ReflectionException;
use ReflectionMethod;
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

        // we need to collect even methods of traits that are always overridden
        foreach ($reflection->getTraits(true) as $trait) {
            foreach ($trait->getNativeReflection()->getMethods() as $traitMethod) {
                if ($this->isUnsupportedMethod($traitMethod)) {
                    continue;
                }

                $traitLine = $traitMethod->getStartLine();
                $traitName = $trait->getName();
                $traitMethodName = $traitMethod->getName();
                $declaringTraitDefinition = $this->getDeclaringTraitDefinition($trait, $traitMethodName);

                if ($traitLine === false) {
                    continue;
                }

                $result[] = [
                    'line' => $traitLine,
                    'definition' => (new MethodDefinition($traitName, $traitMethodName))->toString(),
                    'overriddenDefinitions' => [],
                    'traitOriginDefinition' => $declaringTraitDefinition !== null ? $declaringTraitDefinition->toString() : null,
                ];
            }
        }

        foreach ($nativeReflection->getMethods() as $method) {
            if ($this->isUnsupportedMethod($method)) {
                continue;
            }

            if ($scope->getFile() !== $method->getDeclaringClass()->getFileName()) { // method in parent class
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
            $nativeReflectionMethod = $classReflection->getNativeReflection()->getMethod($methodName);
            $betterReflectionMethod = $nativeReflectionMethod->getBetterReflection();
            $realDeclaringClass = $betterReflectionMethod->getDeclaringClass();

            // when trait method name is aliased, we need the original name
            $realName = Closure::bind(function (): string {
                return $this->name;
            }, $betterReflectionMethod, BetterReflectionMethod::class)();

        } catch (ReflectionException $e) {
            return null;
        }

        if ($realDeclaringClass->isTrait() && $realDeclaringClass->getName() !== $classReflection->getName()) {
            return new MethodDefinition(
                $realDeclaringClass->getName(),
                $realName,
            );
        }

        return null;
    }

    private function isUnsupportedMethod(ReflectionMethod $method): bool
    {
        if ($method->isDestructor()) {
            return true;
        }

        if (!$method->isConstructor() && strpos($method->getName(), '__') === 0) { // magic methods like __toString, __clone, __get, __set etc
            return true;
        }

        if ($method->isConstructor() && $method->isPrivate()) { // e.g. classes with "denied" instantiation
            return true;
        }

        if ($method->getFileName() === false) { // e.g. php core
            return true;
        }

        return strpos($method->getFileName(), '/vendor/') !== false;
    }

}

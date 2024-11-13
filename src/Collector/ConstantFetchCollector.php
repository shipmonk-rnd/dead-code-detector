<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantStringType;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantFetch;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodRef;
use function array_map;

/**
 * @implements Collector<Node, list<string>>
 */
class ConstantFetchCollector implements Collector
{

    /**
     * @var list<ClassConstantFetch>
     */
    private array $accessBuffer = [];

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
        if ($node instanceof ClassConstFetch) {
            $this->registerFetch($node, $scope);
        }

        if (!$scope->isInClass() || $node instanceof ClassMethodsNode) { // @phpstan-ignore-line ignore BC promise
            $data = $this->accessBuffer;
            $this->accessBuffer = [];

            // collect data once per class to save memory & resultCache size
            return $data === []
                ? null
                : array_map(
                    static fn (ClassConstantFetch $fetch): string => $fetch->toString(),
                    $data,
                );
        }

        return null;
    }

    private function registerFetch(ClassConstFetch $node, Scope $scope): void
    {
        $ownerType = $node->class instanceof Name
            ? $scope->resolveTypeByName($node->class)
            : $scope->getType($node->class);

        $constantNames = $node->name instanceof Expr
            ? array_map(static fn (ConstantStringType $string): string => $string->getValue(), $scope->getType($node->name)->getConstantStrings())
            : [$node->name->toString()];

        foreach ($ownerType->getObjectClassReflections() as $classReflection) {
            foreach ($constantNames as $constantName) {
                if ($classReflection->hasConstant($constantName)) {
                    $className = $classReflection->getConstant($constantName)->getDeclaringClass()->getName();

                } else { // call of unknown const (might be present on children)
                    $className = $classReflection->getName(); // TODO untested yet
                }

                $this->accessBuffer[] = new ClassConstantFetch(
                    $this->getCaller($scope),
                    new ClassConstantRef($className, $constantName),
                );
            }
        }
    }

    private function getCaller(Scope $scope): ?ClassMethodRef
    {
        if (!$scope->isInClass()) {
            return null;
        }

        if (!$scope->getFunction() instanceof MethodReflection) {
            return null;
        }

        return new ClassMethodRef($scope->getClassReflection()->getName(), $scope->getFunction()->getName());
    }

}

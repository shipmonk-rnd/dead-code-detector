<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;

/**
 * @implements Collector<Node, list<string>>
 */
final class PropertyAccessCollector implements Collector
{

    use BufferedUsageCollector;

    /**
     * @var list<MemberUsageExcluder>
     */
    private array $memberUsageExcluders;

    /**
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        array $memberUsageExcluders
    )
    {
        $this->memberUsageExcluders = $memberUsageExcluders;
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
        if ($this->isInPropertyHook($scope)) {
            return null;
        }

        if ($node instanceof PropertyFetch && !$scope->isInExpressionAssign($node)) {
            $this->registerInstancePropertyAccess($node, $scope);
        }

        if ($node instanceof StaticPropertyFetch && !$scope->isInExpressionAssign($node)) {
            $this->registerStaticPropertyAccess($node, $scope);
        }

        return $this->emitUsages($scope);
    }

    private function registerInstancePropertyAccess(
        PropertyFetch $node,
        Scope $scope
    ): void
    {
        $propertyNames = $this->getPropertyNames($node, $scope);
        $callerType = $scope->getType($node->var);

        foreach ($propertyNames as $propertyName) {
            foreach ($this->getDeclaringTypesWithProperty($propertyName, $callerType, null) as $propertyRef) {
                $this->registerUsage(
                    new ClassPropertyUsage(
                        UsageOrigin::createRegular($node, $scope),
                        $propertyRef,
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    private function registerStaticPropertyAccess(
        StaticPropertyFetch $node,
        Scope $scope
    ): void
    {
        $propertyNames = $this->getPropertyNames($node, $scope);
        $possibleDescendant = $node->class instanceof Expr || $node->class->toString() === 'static';

        if ($node->class instanceof Expr) {
            $callerType = $scope->getType($node->class);
        } else {
            $callerType = $scope->resolveTypeByName($node->class);
        }

        foreach ($propertyNames as $propertyName) {
            foreach ($this->getDeclaringTypesWithProperty($propertyName, $callerType, $possibleDescendant) as $propertyRef) {
                $this->registerUsage(
                    new ClassPropertyUsage(
                        UsageOrigin::createRegular($node, $scope),
                        $propertyRef,
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    /**
     * @param PropertyFetch|StaticPropertyFetch $fetch
     * @return list<string|null>
     */
    private function getPropertyNames(
        Expr $fetch,
        Scope $scope
    ): array
    {
        if ($fetch->name instanceof Expr) {
            $possiblePropertyNames = [];

            foreach ($scope->getType($fetch->name)->getConstantStrings() as $constantString) {
                $possiblePropertyNames[] = $constantString->getValue();
            }

            return $possiblePropertyNames === []
                ? [null] // unknown property name
                : $possiblePropertyNames;
        }

        return [$fetch->name->toString()];
    }

    /**
     * @return list<ClassPropertyRef<string, string|null>>
     */
    private function getDeclaringTypesWithProperty(
        ?string $propertyName,
        Type $callerType,
        ?bool $isPossibleDescendant
    ): array
    {
        $typeNoNull = TypeCombinator::removeNull($callerType);
        $classReflections = $typeNoNull->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();

        $propertyRefs = [];

        foreach ($classReflections as $classReflection) {
            $possibleDescendant = $isPossibleDescendant ?? !$classReflection->isFinalByKeyword();
            $propertyRefs[] = new ClassPropertyRef(
                $classReflection->getName(),
                $propertyName,
                $possibleDescendant,
            );
        }

        return $propertyRefs;
    }

    private function registerUsage(
        ClassPropertyUsage $usage,
        Node $node,
        Scope $scope
    ): void
    {
        $excluderName = null;

        foreach ($this->memberUsageExcluders as $excludedUsageDecider) {
            if ($excludedUsageDecider->shouldExclude($usage, $node, $scope)) {
                $excluderName = $excludedUsageDecider->getIdentifier();
                break;
            }
        }

        $this->usages[] = new CollectedUsage($usage, $excluderName);
    }

    private function isInPropertyHook(Scope $scope): bool
    {
        $function = $scope->getFunction();
        if ($function === null) {
            return false;
        }

        return $function->isMethodOrPropertyHook() && $function->isPropertyHook();
    }

}

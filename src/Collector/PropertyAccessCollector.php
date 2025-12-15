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
use PHPStan\Type\TypeUtils;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Visitor\PropertyWriteVisitor;

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
        if (!$node instanceof PropertyFetch && !$node instanceof StaticPropertyFetch) {
            return null;
        }

        foreach ($this->getAccessTypes($node) as $accessType) {
            if ($node instanceof PropertyFetch) {
                $this->registerInstancePropertyAccess($node, $scope, $accessType);
            }

            if ($node instanceof StaticPropertyFetch) {
                $this->registerStaticPropertyAccess($node, $scope, $accessType);
            }
        }

        return $this->emitUsages($scope);
    }

    /**
     * @param AccessType::* $accessType
     */
    private function registerInstancePropertyAccess(
        PropertyFetch $node,
        Scope $scope,
        int $accessType
    ): void
    {
        $propertyNames = $this->getPropertyNames($node, $scope);
        $callerType = $scope->getType($node->var);

        foreach ($propertyNames as $propertyName) {
            if ($propertyName !== null && $this->isSelfReferenceInPropertyHook($scope, $propertyName)) {
                continue; // read, nor write access calls other a hook when within a hook
            }
            foreach ($this->getDeclaringTypesWithProperty($propertyName, $callerType, null) as $propertyRef) {
                $this->registerUsage(
                    new ClassPropertyUsage(
                        UsageOrigin::createRegular($node, $scope),
                        $propertyRef,
                        $accessType,
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    /**
     * @param AccessType::* $accessType
     */
    private function registerStaticPropertyAccess(
        StaticPropertyFetch $node,
        Scope $scope,
        int $accessType
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
                        $accessType,
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
     * @return list<ClassPropertyRef<string|null, string|null>>
     */
    private function getDeclaringTypesWithProperty(
        ?string $propertyName,
        Type $callerType,
        ?bool $isPossibleDescendant
    ): array
    {
        $typeNoNull = TypeUtils::toBenevolentUnion( // extract possible accesses even from Class|int
            TypeCombinator::removeNull($callerType), // remove null to support nullsafe access
        );
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

        if ($propertyRefs === []) { // access over unknown type
            $propertyRefs[] = new ClassPropertyRef(null, $propertyName, true);
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

    /**
     * @return iterable<AccessType::*>
     */
    private function getAccessTypes(Node $node): iterable
    {
        if ($node->getAttribute(PropertyWriteVisitor::IS_PROPERTY_WRITE, false) === true) {
            yield AccessType::WRITE;

            if ($node->getAttribute(PropertyWriteVisitor::IS_PROPERTY_WRITE_AND_READ, false) === true) {
                yield AccessType::READ;
            }
        } else {
            yield AccessType::READ;
        }
    }

    private function isSelfReferenceInPropertyHook(Scope $scope, string $propertyName): bool
    {
        $function = $scope->getFunction();
        if ($function === null) {
            return false;
        }

        return $function->isMethodOrPropertyHook() && $function->getHookedPropertyName() === $propertyName;
    }

}

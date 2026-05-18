<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use JsonSerializable;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use Serializable;
use ShipMonk\PHPStan\DeadCode\Cache\UsageCacheStorage;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Visitor\PropertyWriteVisitor;
use function array_map;
use function current;
use function in_array;
use function str_starts_with;

/**
 * @implements Collector<Node, list<string>>
 */
final class PropertyAccessCollector implements Collector
{

    use BufferedUsageCollector;

    /**
     * @param list<string> $analysedPaths
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        UsageCacheStorage $usageCacheStorage,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $analysedPaths,
        private readonly array $memberUsageExcluders,
    )
    {
        $this->usageCacheStorage = $usageCacheStorage;
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
        Scope $scope,
    ): ?array
    {
        if ($node instanceof PropertyFetch) {
            foreach ($this->getAccessTypes($node) as $accessType) {
                $this->registerInstancePropertyAccess($node, $scope, $accessType);
            }
        }

        if ($node instanceof StaticPropertyFetch) {
            foreach ($this->getAccessTypes($node) as $accessType) {
                $this->registerStaticPropertyAccess($node, $scope, $accessType);
            }
        }

        if ($node instanceof InClassMethodNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $this->registerPromotedPropertyWrites($node, $scope);
        }

        if ($node instanceof ArrayCast) {
            $this->registerArrayCastPropertyRead($node, $scope);
        }

        if ($node instanceof FuncCall) {
            $this->registerNativeFunctionPropertyRead($node, $scope);
        }

        return $this->tryFlushBuffer($node, $scope);
    }

    private function registerInstancePropertyAccess(
        PropertyFetch $node,
        Scope $scope,
        AccessType $accessType,
    ): void
    {
        $propertyNames = $this->getPropertyNames($node, $scope);
        $callerType = $scope->getType($node->var);

        foreach ($propertyNames as $propertyName) {
            $callsHook = !$this->isSelfReferenceInPropertyHook($scope, $propertyName);

            foreach ($this->getDeclaringTypesWithProperty($propertyName, $callerType, null) as $propertyRef) {
                $this->registerUsage(
                    new ClassPropertyUsage(
                        UsageOrigin::createRegular($node, $scope),
                        $propertyRef,
                        $accessType,
                        $callsHook,
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    private function registerStaticPropertyAccess(
        StaticPropertyFetch $node,
        Scope $scope,
        AccessType $accessType,
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
        Scope $scope,
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
        ?bool $isPossibleDescendant,
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
            $propertyRefs[] = new ClassPropertyRef(null, $propertyName, possibleDescendant: true);
        }

        return $propertyRefs;
    }

    private function registerUsage(
        ClassPropertyUsage $usage,
        Node $node,
        Scope $scope,
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
     * @param PropertyFetch|StaticPropertyFetch $fetch
     * @return iterable<AccessType>
     */
    private function getAccessTypes(Expr $fetch): iterable
    {
        if ($fetch->getAttribute(PropertyWriteVisitor::IS_PROPERTY_WRITE, false) === true) {
            yield AccessType::WRITE;

            if ($fetch->getAttribute(PropertyWriteVisitor::IS_PROPERTY_WRITE_AND_READ, false) === true) {
                yield AccessType::READ;
            }
        } else {
            yield AccessType::READ;
        }
    }

    private function isSelfReferenceInPropertyHook(
        Scope $scope,
        ?string $propertyName,
    ): bool
    {
        if ($propertyName === null) {
            return false;
        }

        $function = $scope->getFunction();
        if ($function === null) {
            return false;
        }

        return $function->isMethodOrPropertyHook() && $function->getHookedPropertyName() === $propertyName;
    }

    private function registerPromotedPropertyWrites(
        InClassMethodNode $node,
        Scope $scope,
    ): void
    {
        if ($node->getMethodReflection()->getName() !== '__construct') {
            return;
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return;
        }

        $constructor = $classReflection->getNativeReflection()->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }

            $this->registerUsage(
                new ClassPropertyUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassPropertyRef(
                        $classReflection->getName(),
                        $parameter->getName(),
                        possibleDescendant: false,
                    ),
                    AccessType::WRITE,
                ),
                $node,
                $scope,
            );
        }
    }

    private function registerArrayCastPropertyRead(
        ArrayCast $node,
        Scope $scope,
    ): void
    {
        $exprType = $scope->getType($node->expr);
        $this->registerAllPropertyReadsForType($exprType, $node, $scope);
    }

    private function registerNativeFunctionPropertyRead(
        FuncCall $node,
        Scope $scope,
    ): void
    {
        $args = $node->getArgs();

        if ($args === []) {
            return;
        }

        $functionNames = $this->getFunctionNames($node, $scope);
        $firstArgType = $scope->getType(current($args)->value);

        foreach ($functionNames as $functionName) {
            if (in_array($functionName, ['get_object_vars', 'get_mangled_object_vars'], true)) {
                $this->registerAllPropertyReadsForType($firstArgType, $node, $scope);

                if ($this->getObjectClassReflections($firstArgType) === []) {
                    $this->registerUsage(
                        new ClassPropertyUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassPropertyRef(null, null, possibleDescendant: true),
                            AccessType::READ,
                        ),
                        $node,
                        $scope,
                    );
                }
            }

            if ($functionName === 'json_encode') {
                $this->registerJsonEncodePropertyReads($firstArgType, $node, $scope);
            }

            if ($functionName === 'serialize') {
                $this->registerSerializePropertyReads($firstArgType, $node, $scope);
            }

            if ($functionName === 'array_column') {
                $this->registerArrayColumnPropertyReads($firstArgType, $args, $node, $scope);
            }
        }
    }

    /**
     * array_column($array, $column_key, $index_key) reads public properties
     * named by the string $column_key and $index_key from objects in $array.
     * For array elements, it reads array keys instead — not relevant here.
     *
     * @param array<Arg> $args
     */
    private function registerArrayColumnPropertyReads(
        Type $firstArgType,
        array $args,
        FuncCall $node,
        Scope $scope,
    ): void
    {
        $elementType = $firstArgType->getIterableValueType();

        if (!$elementType->isObject()->yes()) {
            return;
        }

        foreach ([1, 2] as $argIndex) {
            if (!isset($args[$argIndex])) {
                continue;
            }

            foreach ($scope->getType($args[$argIndex]->value)->getConstantStrings() as $constantString) {
                $propertyName = $constantString->getValue();

                foreach ($this->getDeclaringTypesWithProperty($propertyName, $elementType, null) as $propertyRef) {
                    $this->registerUsage(
                        new ClassPropertyUsage(
                            UsageOrigin::createRegular($node, $scope),
                            $propertyRef,
                            AccessType::READ,
                        ),
                        $node,
                        $scope,
                    );
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getFunctionNames(
        FuncCall $node,
        Scope $scope,
    ): array
    {
        if ($node->name instanceof Name) {
            return [$node->name->toString()];
        }

        return array_map(
            static fn (ConstantStringType $string): string => $string->getValue(),
            $scope->getType($node->name)->getConstantStrings(),
        );
    }

    /**
     * (array) cast, get_object_vars(), get_mangled_object_vars() — reads all properties
     */
    private function registerAllPropertyReadsForType(
        Type $type,
        Node $node,
        Scope $scope,
    ): void
    {
        foreach ($this->getObjectClassReflections($type) as $classReflection) {
            $this->registerUsage(
                new ClassPropertyUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassPropertyRef(
                        $classReflection->getName(),
                        null,
                        possibleDescendant: !$classReflection->isFinalByKeyword(),
                    ),
                    AccessType::READ,
                ),
                $node,
                $scope,
            );
        }
    }

    /**
     * json_encode() reads public properties of non-JsonSerializable objects,
     * recursively for nested object properties.
     */
    private function registerJsonEncodePropertyReads(
        Type $type,
        Node $node,
        Scope $scope,
    ): void
    {
        $visited = [];

        foreach ($this->getObjectClassReflections($type) as $classReflection) {
            $this->registerJsonEncodePropertyReadsRecursive($classReflection, $node, $scope, $visited);
        }
    }

    /**
     * @param array<string, true> $visited
     */
    private function registerJsonEncodePropertyReadsRecursive(
        ClassReflection $classReflection,
        Node $node,
        Scope $scope,
        array &$visited,
    ): void
    {
        if ($classReflection->implementsInterface(JsonSerializable::class)) {
            return;
        }

        $className = $classReflection->getName();

        if (isset($visited[$className])) {
            return;
        }

        $visited[$className] = true;

        $this->registerUsage(
            new ClassPropertyUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassPropertyRef(
                    $className,
                    null,
                    possibleDescendant: !$classReflection->isFinalByKeyword(),
                ),
                AccessType::READ,
            ),
            $node,
            $scope,
        );

        foreach ($classReflection->getNativeReflection()->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            $propertyReflection = $classReflection->getNativeProperty($property->getName());

            foreach ($propertyReflection->getReadableType()->getReferencedClasses() as $nestedClassName) {
                $nestedClassReflection = $this->resolveClassForRecursion($nestedClassName);

                if ($nestedClassReflection === null) {
                    continue;
                }

                $this->registerJsonEncodePropertyReadsRecursive($nestedClassReflection, $node, $scope, $visited);
            }
        }
    }

    /**
     * serialize() reads all properties when no __serialize() or Serializable exists,
     * recursively for nested object properties.
     * When __sleep() exists, we conservatively mark all properties as read since we cannot
     * determine the return value statically.
     */
    private function registerSerializePropertyReads(
        Type $type,
        Node $node,
        Scope $scope,
    ): void
    {
        $visited = [];

        foreach ($this->getObjectClassReflections($type) as $classReflection) {
            $this->registerSerializePropertyReadsRecursive($classReflection, $node, $scope, $visited);
        }
    }

    /**
     * @param array<string, true> $visited
     */
    private function registerSerializePropertyReadsRecursive(
        ClassReflection $classReflection,
        Node $node,
        Scope $scope,
        array &$visited,
    ): void
    {
        if ($this->hasCustomSerializationLogic($classReflection)) {
            return;
        }

        $className = $classReflection->getName();

        if (isset($visited[$className])) {
            return;
        }

        $visited[$className] = true;

        $this->registerUsage(
            new ClassPropertyUsage(
                UsageOrigin::createRegular($node, $scope),
                new ClassPropertyRef(
                    $className,
                    null,
                    possibleDescendant: !$classReflection->isFinalByKeyword(),
                ),
                AccessType::READ,
            ),
            $node,
            $scope,
        );

        foreach ($classReflection->getNativeReflection()->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyReflection = $classReflection->getNativeProperty($property->getName());

            foreach ($propertyReflection->getReadableType()->getReferencedClasses() as $nestedClassName) {
                $nestedClassReflection = $this->resolveClassForRecursion($nestedClassName);

                if ($nestedClassReflection === null) {
                    continue;
                }

                $this->registerSerializePropertyReadsRecursive($nestedClassReflection, $node, $scope, $visited);
            }
        }
    }

    private function hasCustomSerializationLogic(ClassReflection $classReflection): bool
    {
        if ($classReflection->hasNativeMethod('__serialize')) {
            return true;
        }

        if ($classReflection->implementsInterface(Serializable::class)) {
            return true;
        }

        return false;
    }

    private function resolveClassForRecursion(string $className): ?ClassReflection
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        $fileName = $classReflection->getFileName();

        if ($fileName === null) {
            return null;
        }

        foreach ($this->analysedPaths as $path) {
            if (str_starts_with($fileName, $path)) {
                return $classReflection;
            }
        }

        return null;
    }

    /**
     * @return list<ClassReflection>
     */
    private function getObjectClassReflections(Type $type): array
    {
        $typeNoNull = TypeUtils::toBenevolentUnion(
            TypeCombinator::removeNull($type),
        );

        return $typeNoNull->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();
    }

}

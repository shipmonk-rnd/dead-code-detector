<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_map;
use function count;
use function current;
use function explode;
use function strpos;

/**
 * @implements Collector<Node, list<string>>
 */
class ConstantFetchCollector implements Collector
{

    use BufferedUsageCollector;

    private ReflectionProvider $reflectionProvider;

    /**
     * @var list<MemberUsageExcluder>
     */
    private array $memberUsageExcluders;

    /**
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        array $memberUsageExcluders
    )
    {
        $this->reflectionProvider = $reflectionProvider;
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
        if ($node instanceof ClassConstFetch) {
            $this->registerFetch($node, $scope);
        }

        if ($node instanceof FuncCall) {
            $this->registerFunctionCall($node, $scope);
        }

        return $this->tryFlushBuffer($node, $scope);
    }

    private function registerFunctionCall(FuncCall $node, Scope $scope): void
    {
        if (count($node->args) !== 1) {
            return;
        }

        /** @var Arg $firstArg */
        $firstArg = current($node->args);

        if ($node->name instanceof Name) {
            $functionNames = [$node->name->toString()];
        } else {
            $nameType = $scope->getType($node->name);
            $functionNames = array_map(static fn (ConstantStringType $string): string => $string->getValue(), $nameType->getConstantStrings());
        }

        foreach ($functionNames as $functionName) {
            if ($functionName !== 'constant') {
                continue;
            }

            $argumentType = $scope->getType($firstArg->value);

            foreach ($argumentType->getConstantStrings() as $constantString) {
                if (strpos($constantString->getValue(), '::') === false) {
                    continue;
                }

                // @phpstan-ignore offsetAccess.notFound
                [$className, $constantName] = explode('::', $constantString->getValue());

                if ($this->reflectionProvider->hasClass($className)) {
                    $reflection = $this->reflectionProvider->getClass($className);

                    if ($reflection->hasConstant($constantName)) {
                        $className = $reflection->getConstant($constantName)->getDeclaringClass()->getName();
                    }
                }

                $this->registerUsage(
                    new ClassConstantUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassConstantRef($className, $constantName, true),
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    private function registerFetch(ClassConstFetch $node, Scope $scope): void
    {
        if ($node->class instanceof Expr) {
            $ownerType = $scope->getType($node->class);
            $possibleDescendantFetch = true;
        } else {
            $ownerType = $scope->resolveTypeByName($node->class);
            $possibleDescendantFetch = $node->class->toString() === 'static';
        }

        $constantNames = $node->name instanceof Expr
            ? array_map(static fn (ConstantStringType $string): string => $string->getValue(), $scope->getType($node->name)->getConstantStrings())
            : [$node->name->toString()];

        foreach ($constantNames as $constantName) {
            if ($constantName === 'class') {
                continue; // reserved for class name fetching
            }

            foreach ($this->getDeclaringTypesWithConstant($ownerType, $constantName) as $className) {
                $this->registerUsage(
                    new ClassConstantUsage(
                        UsageOrigin::createRegular($node, $scope),
                        new ClassConstantRef($className, $constantName, $possibleDescendantFetch),
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    /**
     * @return list<class-string<object>|null>
     */
    private function getDeclaringTypesWithConstant(
        Type $type,
        string $constantName
    ): array
    {
        $typeNormalized = TypeUtils::toBenevolentUnion($type); // extract possible calls even from Class|int
        $classReflections = $typeNormalized->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();

        $result = [];

        foreach ($classReflections as $classReflection) {
            if ($classReflection->hasConstant($constantName)) {
                $result[] = $classReflection->getConstant($constantName)->getDeclaringClass()->getName();

            } else { // unknown constant fetch (might be present on children)
                $result[] = $classReflection->getName();
            }
        }

        if ($result === []) {
            $result[] = null; // call over unknown type
        }

        return $result;
    }

    private function registerUsage(ClassConstantUsage $usage, Node $node, Scope $scope): void
    {
        $excluderName = null;

        foreach ($this->memberUsageExcluders as $excludedUsageDecider) {
            if ($excludedUsageDecider->shouldExclude($usage, $node, $scope)) {
                $excluderName = $excludedUsageDecider->getIdentifier();
                break;
            }
        }

        $this->usageBuffer[] = new CollectedUsage($usage, $excluderName);
    }

}

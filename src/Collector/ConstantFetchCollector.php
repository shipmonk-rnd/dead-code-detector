<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\NameScope;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\PhpDocParser\Ast\NodeTraverser as PhpDocNodeTraverser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;
use ShipMonk\PHPStan\DeadCode\Cache\UsageCacheStorage;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Visitor\PhpDocConstFetchCollectingVisitor;
use function array_map;
use function count;
use function current;
use function explode;
use function str_contains;
use function strtolower;

/**
 * @implements Collector<Node, list<string>>
 */
final class ConstantFetchCollector implements Collector
{

    use BufferedUsageCollector;

    /**
     * @param list<MemberUsageExcluder> $memberUsageExcluders
     */
    public function __construct(
        UsageCacheStorage $usageCacheStorage,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly FileTypeMapper $fileTypeMapper,
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
        if ($node instanceof ClassConstFetch) {
            $this->registerFetch($node, $scope);
        }

        if ($node instanceof FuncCall) {
            $this->registerFunctionCall($node, $scope);
        }

        $this->registerPhpDocConstantFetches($node, $scope);

        return $this->tryFlushBuffer($node, $scope);
    }

    private function registerFunctionCall(
        FuncCall $node,
        Scope $scope,
    ): void
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
                if (!str_contains($constantString->getValue(), '::')) {
                    continue;
                }

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
                        new ClassConstantRef($className, $constantName, possibleDescendant: true, isEnumCase: TrinaryLogic::createMaybe()),
                    ),
                    $node,
                    $scope,
                );
            }
        }
    }

    /**
     * PHPStan eagerly resolves e.g. int<1, self::MAX> down to a literal int<1, 100>, so the reference
     * to the constant survives only in the raw PhpDoc type AST. We walk it for ConstFetchNode occurrences.
     */
    private function registerPhpDocConstantFetches(
        Node $node,
        Scope $scope,
    ): void
    {
        $docComment = $node->getDocComment();

        if ($docComment === null || !str_contains($docComment->getText(), '::')) {
            return;
        }

        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $functionName = $node->name->toString();
        } else {
            $function = $scope->getFunction();
            $functionName = $function !== null ? $function->getName() : null;
        }

        $resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $scope->isInClass() ? $scope->getClassReflection()->getName() : null,
            $scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
            $functionName,
            $docComment->getText(),
        );

        $nameScope = $resolvedPhpDoc->getNullableNameScope();

        if ($nameScope === null) {
            return;
        }

        $visitor = new PhpDocConstFetchCollectingVisitor();
        (new PhpDocNodeTraverser([$visitor]))->traverse($resolvedPhpDoc->getPhpDocNodes());

        foreach ($visitor->getConstFetchNodes() as $constFetchNode) {
            if (str_contains($constFetchNode->name, '*')) {
                continue; // constant mask (e.g. self::SIZE_*), see https://github.com/shipmonk-rnd/dead-code-detector/issues/223
            }

            $ownerClassName = $this->resolvePhpDocConstFetchOwner($constFetchNode->className, $nameScope, $scope);

            if ($ownerClassName === null) {
                continue;
            }

            $possibleDescendant = strtolower($constFetchNode->className) === 'static';

            foreach ($this->getDeclaringTypesWithConstant(new ObjectType($ownerClassName), $constFetchNode->name, $possibleDescendant) as $constantRef) {
                $usage = new ClassConstantUsage(UsageOrigin::createRegular($node, $scope), $constantRef);
                $this->registerUsage($usage, $node, $scope);
            }
        }
    }

    private function resolvePhpDocConstFetchOwner(
        string $className,
        NameScope $nameScope,
        Scope $scope,
    ): ?string
    {
        $lowerClassName = strtolower($className);

        if ($lowerClassName === 'self' || $lowerClassName === 'static') {
            return $scope->isInClass() ? $scope->getClassReflection()->getName() : null;
        }

        if ($lowerClassName === 'parent') {
            if (!$scope->isInClass()) {
                return null;
            }

            $parent = $scope->getClassReflection()->getParentClass();

            return $parent !== null ? $parent->getName() : null;
        }

        return $nameScope->resolveStringName($className);
    }

    private function registerFetch(
        ClassConstFetch $node,
        Scope $scope,
    ): void
    {
        if ($node->class instanceof Expr) {
            $ownerType = $scope->getType($node->class);
            $possibleDescendantFetch = null;
        } else {
            $ownerType = $scope->resolveTypeByName($node->class);
            $possibleDescendantFetch = $node->class->toString() === 'static';
        }

        $constantNames = $this->getConstantNames($node, $scope);

        foreach ($constantNames as $constantName) {
            if ($constantName === 'class') {
                continue; // reserved for class name fetching
            }

            foreach ($this->getDeclaringTypesWithConstant($ownerType, $constantName, $possibleDescendantFetch) as $constantRef) {
                $origin = UsageOrigin::createRegular($node, $scope);
                $usage = new ClassConstantUsage($origin, $constantRef);

                $this->registerUsage($usage, $node, $scope);
            }
        }
    }

    /**
     * @return list<string|null>
     */
    private function getConstantNames(
        ClassConstFetch $fetch,
        Scope $scope,
    ): array
    {
        if ($fetch->name instanceof Expr) {
            $possibleConstantNames = [];

            foreach ($scope->getType($fetch->name)->getConstantStrings() as $constantString) {
                $possibleConstantNames[] = $constantString->getValue();
            }

            return $possibleConstantNames === []
                ? [null] // unknown constant name
                : $possibleConstantNames;
        }

        return [$fetch->name->toString()];
    }

    /**
     * @return list<ClassConstantRef<?string, ?string>>
     */
    private function getDeclaringTypesWithConstant(
        Type $type,
        ?string $constantName,
        ?bool $isPossibleDescendant,
    ): array
    {
        $typeNormalized = TypeUtils::toBenevolentUnion($type) // extract possible fetches even from Class|int
            ->getObjectTypeOrClassStringObjectType();
        $classReflections = $typeNormalized->getObjectClassReflections();

        $result = [];
        $isEnumCaseFetch = $typeNormalized->isEnum()->no() ? TrinaryLogic::createNo() : TrinaryLogic::createMaybe();

        foreach ($classReflections as $classReflection) {
            $possibleDescendant = $isPossibleDescendant ?? !$classReflection->isFinalByKeyword();
            $result[] = new ClassConstantRef(
                $classReflection->getName(),
                $constantName,
                $possibleDescendant,
                $isEnumCaseFetch,
            );
        }

        if ($result === []) { // call over unknown type
            $result[] = new ClassConstantRef(null, $constantName, possibleDescendant: true, isEnumCase: $isEnumCaseFetch);
        }

        return $result;
    }

    private function registerUsage(
        ClassConstantUsage $usage,
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

}

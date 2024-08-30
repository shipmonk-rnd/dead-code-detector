<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use function array_map;

/**
 * @implements Collector<Node, list<string>>
 */
class MethodCallCollector implements Collector
{

    private ReflectionProvider $reflectionProvider;

    /**
     * @var list<Call>
     */
    private array $callsBuffer = [];

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<string>|null
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): ?array
    {
        if ($node instanceof MethodCallableNode) { // @phpstan-ignore-line ignore BC promise
            $this->registerMethodCall($node->getOriginalNode(), $scope);
        }

        if ($node instanceof StaticMethodCallableNode) { // @phpstan-ignore-line ignore BC promise
            $this->registerStaticCall($node->getOriginalNode(), $scope);
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof New_) {
            $this->registerMethodCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            $this->registerStaticCall($node, $scope);
        }

        if ($node instanceof Array_) {
            $this->registerArrayCallable($node, $scope);
        }

        if ($node instanceof Attribute) {
            $this->registerAttribute($node, $scope);
        }

        if (!$scope->isInClass() || $node instanceof ClassMethodsNode) { // @phpstan-ignore-line ignore BC promise
            $data = $this->callsBuffer;
            $this->callsBuffer = [];

            // collect data once per class to save memory & resultCache size
            return $data === []
                ? null
                : array_map(
                    static fn (Call $call): string => $call->toString(),
                    $data,
                );
        }

        return null;
    }

    /**
     * @param NullsafeMethodCall|MethodCall|New_ $methodCall
     */
    private function registerMethodCall(
        CallLike $methodCall,
        Scope $scope
    ): void
    {
        $methodName = $this->getMethodName($methodCall);

        if ($methodCall instanceof New_) {
            if ($methodCall->class instanceof Expr) {
                $callerType = $scope->getType($methodCall->class);
                $possibleDescendantCall = true;

            } elseif ($methodCall->class instanceof Name) {
                $callerType = $scope->resolveTypeByName($methodCall->class);
                $possibleDescendantCall = $methodCall->class->toString() === 'static';

            } else {
                return;
            }
        } else {
            $callerType = $scope->getType($methodCall->var);
            $possibleDescendantCall = true;
        }

        if ($methodName === null) {
            return;
        }

        foreach ($this->getReflectionsWithMethod($callerType, $methodName) as $classWithMethod) {
            $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
            $this->callsBuffer[] = new Call($className, $methodName, $possibleDescendantCall);
        }
    }

    private function registerStaticCall(
        StaticCall $staticCall,
        Scope $scope
    ): void
    {
        $methodName = $this->getMethodName($staticCall);

        if ($methodName === null) {
            return;
        }

        if ($staticCall->class instanceof Expr) {
            $callerType = $scope->getType($staticCall->class);
            $classReflections = $this->getReflectionsWithMethod($callerType, $methodName);
            $possibleDescendantCall = true;

        } else {
            $className = $scope->resolveName($staticCall->class);
            $possibleDescendantCall = $staticCall->class->toString() === 'static';

            if ($this->reflectionProvider->hasClass($className)) {
                $classReflections = [
                    $this->reflectionProvider->getClass($className),
                ];
            } else {
                $classReflections = [];
            }
        }

        foreach ($classReflections as $classWithMethod) {
            $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
            $this->callsBuffer[] = new Call($className, $methodName, $possibleDescendantCall);
        }
    }

    private function registerArrayCallable(
        Array_ $array,
        Scope $scope
    ): void
    {
        if ($scope->getType($array)->isCallable()->yes()) {
            foreach ($scope->getType($array)->getConstantArrays() as $constantArray) {
                $callableTypeAndNames = $constantArray->findTypeAndMethodNames();

                foreach ($callableTypeAndNames as $typeAndName) {
                    $caller = $typeAndName->getType();
                    $methodName = $typeAndName->getMethod();

                    // currently always true, see https://github.com/phpstan/phpstan-src/pull/3372
                    $possibleDescendantCall = !$caller->isClassStringType()->yes();

                    foreach ($this->getReflectionsWithMethod($caller, $methodName) as $classWithMethod) {
                        $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
                        $this->callsBuffer[] = new Call($className, $methodName, $possibleDescendantCall);
                    }
                }
            }
        }
    }

    private function registerAttribute(Attribute $node, Scope $scope): void
    {
        $this->callsBuffer[] = new Call($scope->resolveName($node->name), '__construct', false);
    }

    /**
     * @param NullsafeMethodCall|MethodCall|StaticCall|New_ $call
     */
    private function getMethodName(CallLike $call): ?string
    {
        if ($call instanceof New_) {
            return '__construct';
        }

        if (!$call->name instanceof Identifier) {
            return null;
        }

        return $call->name->toString();
    }

    /**
     * @return iterable<ClassReflection>
     */
    private function getReflectionsWithMethod(Type $type, string $methodName): iterable
    {
        // remove null to support nullsafe calls
        $typeNoNull = TypeCombinator::removeNull($type);
        $classReflections = $typeNoNull->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();

        foreach ($classReflections as $classReflection) {
            if ($classReflection->hasMethod($methodName)) {
                yield $classReflection;
            }
        }
    }

}

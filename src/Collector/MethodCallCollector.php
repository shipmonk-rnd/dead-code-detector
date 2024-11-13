<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodCall;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodRef;
use function array_map;

/**
 * @implements Collector<Node, list<string>>
 */
class MethodCallCollector implements Collector
{

    /**
     * @var list<ClassMethodCall>
     */
    private array $callsBuffer = [];

    private bool $trackCallsOnMixed;

    public function __construct(bool $trackCallsOnMixed)
    {
        $this->trackCallsOnMixed = $trackCallsOnMixed;
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

        if ($node instanceof Clone_) {
            $this->registerClone($node, $scope);
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
                    static fn (ClassMethodCall $call): string => $call->serialize(),
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
        $methodNames = $this->getMethodName($methodCall, $scope);

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

        foreach ($methodNames as $methodName) {
            foreach ($this->getDeclaringTypesWithMethod($scope, $callerType, $methodName, TrinaryLogic::createNo()) as $className) {
                $this->callsBuffer[] = new ClassMethodCall(
                    $this->getCaller($scope),
                    new ClassMethodRef($className, $methodName),
                    $possibleDescendantCall,
                );
            }
        }
    }

    private function registerStaticCall(
        StaticCall $staticCall,
        Scope $scope
    ): void
    {
        $methodNames = $this->getMethodName($staticCall, $scope);

        if ($staticCall->class instanceof Expr) {
            $callerType = $scope->getType($staticCall->class);
            $possibleDescendantCall = true;

        } else {
            $callerType = $scope->resolveTypeByName($staticCall->class);
            $possibleDescendantCall = $staticCall->class->toString() === 'static';
        }

        foreach ($methodNames as $methodName) {
            foreach ($this->getDeclaringTypesWithMethod($scope, $callerType, $methodName, TrinaryLogic::createYes()) as $className) {
                $this->callsBuffer[] = new ClassMethodCall(
                    $this->getCaller($scope),
                    new ClassMethodRef($className, $methodName),
                    $possibleDescendantCall,
                );
            }
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
                    $possibleDescendantCall = !$caller->isClassString()->yes();

                    foreach ($this->getDeclaringTypesWithMethod($scope, $caller, $methodName, TrinaryLogic::createMaybe()) as $className) {
                        $this->callsBuffer[] = new ClassMethodCall(
                            $this->getCaller($scope),
                            new ClassMethodRef($className, $methodName),
                            $possibleDescendantCall,
                        );
                    }
                }
            }
        }
    }

    private function registerAttribute(Attribute $node, Scope $scope): void
    {
        $this->callsBuffer[] = new ClassMethodCall(
            null,
            new ClassMethodRef($scope->resolveName($node->name), '__construct'),
            false,
        );
    }

    private function registerClone(Clone_ $node, Scope $scope): void
    {
        $methodName = '__clone';
        $callerType = $scope->getType($node->expr);

        foreach ($this->getDeclaringTypesWithMethod($scope, $callerType, $methodName, TrinaryLogic::createNo()) as $className) {
            $this->callsBuffer[] = new ClassMethodCall(
                $this->getCaller($scope),
                new ClassMethodRef($className, $methodName),
                true,
            );
        }
    }

    /**
     * @param NullsafeMethodCall|MethodCall|StaticCall|New_ $call
     * @return list<string>
     */
    private function getMethodName(CallLike $call, Scope $scope): array
    {
        if ($call instanceof New_) {
            return ['__construct'];
        }

        if ($call->name instanceof Expr) {
            $possibleMethodNames = [];

            foreach ($scope->getType($call->name)->getConstantStrings() as $constantString) {
                $possibleMethodNames[] = $constantString->getValue();
            }

            return $possibleMethodNames;
        }

        return [$call->name->toString()];
    }

    /**
     * @return list<class-string<object>|null>
     */
    private function getDeclaringTypesWithMethod(
        Scope $scope,
        Type $type,
        string $methodName,
        TrinaryLogic $isStaticCall
    ): array
    {
        $typeNoNull = TypeCombinator::removeNull($type); // remove null to support nullsafe calls
        $typeNormalized = TypeUtils::toBenevolentUnion($typeNoNull); // extract possible calls even from Class|int
        $classReflections = $typeNormalized->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();

        $result = [];

        foreach ($classReflections as $classReflection) {
            if ($classReflection->hasMethod($methodName)) {
                $result[] = $classReflection->getMethod($methodName, $scope)->getDeclaringClass()->getName();

            } else { // call of unknown method (might be present on children)
                $result[] = $classReflection->getName();
            }
        }

        if ($this->trackCallsOnMixed) {
            $canBeObjectCall = !$typeNoNull->isObject()->no() && !$isStaticCall->yes();
            $canBeClassStringCall = !$typeNoNull->isClassString()->no() && !$isStaticCall->no();

            if ($result === [] && ($canBeObjectCall || $canBeClassStringCall)) {
                $result[] = null; // call over unknown type
            }
        }

        return $result;
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

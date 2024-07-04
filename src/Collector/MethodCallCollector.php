<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use ShipMonk\PHPStan\DeadCode\Helper\DeadCodeHelper;

/**
 * @implements Collector<Node, list<string>>
 */
class MethodCallCollector implements Collector
{

    private ReflectionProvider $reflectionProvider;

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
            return $this->registerMethodCall($node->getOriginalNode(), $scope);
        }

        if ($node instanceof StaticMethodCallableNode) { // @phpstan-ignore-line ignore BC promise
            return $this->registerStaticCall($node->getOriginalNode(), $scope);
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            return $this->registerMethodCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->registerStaticCall($node, $scope);
        }

        if ($node instanceof FuncCall) {
            return $this->registerFuncCall($node, $scope);
        }

        return null;
    }

    /**
     * @param NullsafeMethodCall|MethodCall $methodCall
     * @return list<string>|null
     */
    private function registerMethodCall(
        CallLike $methodCall,
        Scope $scope
    ): ?array
    {
        $methodName = $this->getMethodName($methodCall);
        $callerType = $scope->getType($methodCall->var);

        if ($methodName === null) {
            return null;
        }

        $result = [];

        foreach ($this->getReflectionsWithMethod($callerType, $methodName) as $classWithMethod) {
            $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
            $result[] = DeadCodeHelper::composeMethodKey($className, $methodName);
        }

        return $result !== [] ? $result : null;
    }

    /**
     * @return list<string>|null
     */
    private function registerStaticCall(
        StaticCall $staticCall,
        Scope $scope
    ): ?array
    {
        $methodName = $this->getMethodName($staticCall);

        if ($methodName === null) {
            return null;
        }

        if ($staticCall->class instanceof Expr) {
            $callerType = $scope->getType($staticCall->class);
            $classReflections = $this->getReflectionsWithMethod($callerType, $methodName);
        } else {
            $className = $scope->resolveName($staticCall->class);

            if ($this->reflectionProvider->hasClass($className)) {
                $classReflections = [
                    $this->reflectionProvider->getClass($className),
                ];
            }
        }

        $result = [];

        foreach ($classReflections as $classWithMethod) {
            $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
            $result[] = DeadCodeHelper::composeMethodKey($className, $methodName);
        }

        return $result !== [] ? $result : null;
    }

    /**
     * @return list<string>|null
     */
    private function registerFuncCall(
        FuncCall $functionCall,
        Scope $scope
    ): ?array
    {
        if (!$functionCall->name instanceof Name || $functionCall->name->toString() !== 'array_map') { // we should support all native fns
            return null;
        }

        $callableType = $scope->getType($functionCall->getArgs()[0]->value);

        if ($callableType->isCallable()->yes()) {
            foreach ($callableType->getConstantArrays() as $constantArray) {
                $callableTypeAndNames = $constantArray->findTypeAndMethodNames();

                $result = [];

                foreach ($callableTypeAndNames as $typeAndName) {
                    $methodName = $typeAndName->getMethod();

                    foreach ($this->getReflectionsWithMethod($typeAndName->getType(), $methodName) as $classWithMethod) {
                        $className = $classWithMethod->getMethod($methodName, $scope)->getDeclaringClass()->getName();
                        $result[] = DeadCodeHelper::composeMethodKey($className, $methodName);
                    }
                }

                if ($result !== []) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * @param NullsafeMethodCall|MethodCall|StaticCall $call
     */
    private function getMethodName(CallLike $call): ?string
    {
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
        $classReflections = $type->getObjectClassReflections();

        foreach ($classReflections as $classReflection) {
            if ($classReflection->hasMethod($methodName)) {
                yield $classReflection;
            }
        }
    }

}

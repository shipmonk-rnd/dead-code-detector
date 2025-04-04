<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use ReflectionClass;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_key_first;
use function count;
use function in_array;

class ReflectionUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(
        bool $enabled
    )
    {
        $this->enabled = $enabled;
    }

    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$this->enabled) {
            return [];
        }

        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function processMethodCall(MethodCall $node, Scope $scope): array
    {
        $callerType = $scope->getType($node->var);
        $methodNames = $this->getMethodNames($node, $scope);

        $usedConstants = [];
        $usedMethods = [];

        foreach ($methodNames as $methodName) {
            foreach ($callerType->getObjectClassReflections() as $reflection) {
                if (!$reflection->is(ReflectionClass::class)) {
                    continue;
                }

                // ideally, we should check if T is covariant (marks children as used) or invariant (should not mark children as used)
                // the default changed in PHP 8.4, see: https://github.com/phpstan/phpstan/issues/12459#issuecomment-2607123277
                foreach ($reflection->getActiveTemplateTypeMap()->getTypes() as $genericType) {
                    $genericClassNames = $genericType->getObjectClassNames() === []
                        ? [null] // call over ReflectionClass without specifying the generic type
                        : $genericType->getObjectClassNames();

                    foreach ($genericClassNames as $genericClassName) {
                        $usedConstants = [
                            ...$usedConstants,
                            ...$this->extractConstantsUsedByReflection($genericClassName, $methodName, $node->getArgs(), $node, $scope),
                        ];
                        $usedMethods = [
                            ...$usedMethods,
                            ...$this->extractMethodsUsedByReflection($genericClassName, $methodName, $node->getArgs(), $node, $scope),
                        ];
                    }
                }
            }
        }

        return [
            ...$usedConstants,
            ...$usedMethods,
        ];
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassConstantUsage>
     */
    private function extractConstantsUsedByReflection(
        ?string $genericClassName,
        string $methodName,
        array $args,
        Node $node,
        Scope $scope
    ): array
    {
        $usedConstants = [];

        if ($methodName === 'getConstants' || $methodName === 'getReflectionConstants') {
            $usedConstants[] = $this->createConstantUsage($node, $scope, $genericClassName, null);
        }

        if (($methodName === 'getConstant' || $methodName === 'getReflectionConstant') && count($args) === 1) {
            $firstArg = $args[array_key_first($args)];

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                $usedConstants[] = $this->createConstantUsage($node, $scope, $genericClassName, $constantString->getValue());
            }
        }

        return $usedConstants;
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassMethodUsage>
     */
    private function extractMethodsUsedByReflection(
        ?string $genericClassName,
        string $methodName,
        array $args,
        Node $node,
        Scope $scope
    ): array
    {
        $usedMethods = [];

        if ($methodName === 'getMethods') {
            $usedMethods[] = $this->createMethodUsage($node, $scope, $genericClassName, null);
        }

        if ($methodName === 'getMethod' && count($args) === 1) {
            $firstArg = $args[array_key_first($args)];

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                $usedMethods[] = $this->createMethodUsage($node, $scope, $genericClassName, $constantString->getValue());
            }
        }

        if (in_array($methodName, ['getConstructor', 'newInstance', 'newInstanceArgs'], true)) {
            $usedMethods[] = $this->createMethodUsage($node, $scope, $genericClassName, '__construct');
        }

        return $usedMethods;
    }

    /**
     * @param NullsafeMethodCall|MethodCall|StaticCall|New_ $call
     * @return list<string>
     */
    private function getMethodNames(CallLike $call, Scope $scope): array
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

    private function createConstantUsage(
        Node $node,
        Scope $scope,
        ?string $className,
        ?string $constantName
    ): ClassConstantUsage
    {
        return new ClassConstantUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassConstantRef(
                $className,
                $constantName,
                true,
            ),
        );
    }

    private function createMethodUsage(
        Node $node,
        Scope $scope,
        ?string $className,
        ?string $methodName
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassMethodRef(
                $className,
                $methodName,
                true,
            ),
        );
    }

}

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
use PHPStan\Reflection\ClassReflection;
use ReflectionClass;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use function array_key_first;
use function count;
use function in_array;

class ReflectionUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(bool $enabled)
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

                foreach ($reflection->getActiveTemplateTypeMap()->getTypes() as $genericType) {
                    foreach ($genericType->getObjectClassReflections() as $genericReflection) {
                        $usedConstants = [
                            ...$usedConstants,
                            ...$this->extractConstantsUsedByReflection($methodName, $genericReflection, $node->getArgs(), $scope),
                        ];
                        $usedMethods = [
                            ...$usedMethods,
                            ...$this->extractMethodsUsedByReflection($methodName, $genericReflection, $node->getArgs(), $scope),
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
        string $methodName,
        ClassReflection $genericReflection,
        array $args,
        Scope $scope
    ): array
    {
        $usedConstants = [];

        if ($methodName === 'getConstants' || $methodName === 'getReflectionConstants') {
            foreach ($genericReflection->getNativeReflection()->getReflectionConstants() as $reflectionConstant) {
                $usedConstants[] = $this->createConstantUsage($reflectionConstant->getDeclaringClass()->getName(), $reflectionConstant->getName());
            }
        }

        if (($methodName === 'getConstant' || $methodName === 'getReflectionConstant') && count($args) === 1) {
            $firstArg = $args[array_key_first($args)]; // @phpstan-ignore offsetAccess.notFound

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                if (!$genericReflection->hasConstant($constantString->getValue())) {
                    continue;
                }

                $usedConstants[] = $this->createConstantUsage($genericReflection->getName(), $constantString->getValue());
            }
        }

        return $usedConstants;
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassMethodUsage>
     */
    private function extractMethodsUsedByReflection(
        string $methodName,
        ClassReflection $genericReflection,
        array $args,
        Scope $scope
    ): array
    {
        $usedMethods = [];

        if ($methodName === 'getMethods') {
            foreach ($genericReflection->getNativeReflection()->getMethods() as $reflectionMethod) {
                $usedMethods[] = $this->createMethodUsage($reflectionMethod->getDeclaringClass()->getName(), $reflectionMethod->getName());
            }
        }

        if ($methodName === 'getMethod' && count($args) === 1) {
            $firstArg = $args[array_key_first($args)]; // @phpstan-ignore offsetAccess.notFound

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                if (!$genericReflection->hasMethod($constantString->getValue())) {
                    continue;
                }

                $usedMethods[] = $this->createMethodUsage($genericReflection->getName(), $constantString->getValue());
            }
        }

        if (in_array($methodName, ['getConstructor', 'newInstance', 'newInstanceArgs'], true)) {
            $constructor = $genericReflection->getNativeReflection()->getConstructor();

            if ($constructor !== null) {
                $usedMethods[] = $this->createMethodUsage($constructor->getDeclaringClass()->getName(), '__construct');
            }
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

    private function createConstantUsage(string $className, string $constantName): ClassConstantUsage
    {
        return new ClassConstantUsage(
            null,
            new ClassConstantRef(
                $className,
                $constantName,
                false,
            ),
        );
    }

    private function createMethodUsage(string $className, string $methodName): ClassMethodUsage
    {
        return new ClassMethodUsage(
            null,
            new ClassMethodRef(
                $className,
                $methodName,
                false,
            ),
        );
    }

}

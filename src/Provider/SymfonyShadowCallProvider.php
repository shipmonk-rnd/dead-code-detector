<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Symfony\ServiceMapFactory;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\Method;

class SymfonyShadowCallProvider implements ShadowMethodCallProvider
{

    /**
     * @var array<string, true>
     */
    private array $dicClasses = [];

    public function __construct(?ServiceMapFactory $serviceMapFactory)
    {
        if ($serviceMapFactory !== null) {
            foreach ($serviceMapFactory->create()->getServices() as $service) { // @phpstan-ignore phpstanApi.method, phpstanApi.method
                $dicClass = $service->getClass(); // @phpstan-ignore phpstanApi.method

                if ($dicClass === null) {
                    continue;
                }

                $this->dicClasses[$dicClass] = true;
            }
        }
    }

    public function getShadowMethodCalls(Node $node, Scope $scope): array
    {
        $calls = [];

        if ($node instanceof ClassMethod && $node->name->name === '__construct') {
            $classReflection = $scope->getClassReflection();

            if ($classReflection === null) {
                return [];
            }

            if (!isset($this->dicClasses[$classReflection->getName()])) {
                return [];
            }

            foreach ($node->getParams() as $param) {
                $type = $scope->getFunctionType($param->type, $scope->isParameterValueNullable($param), $param->variadic);

                foreach ($type->getObjectClassReflections() as $paramClassReflection) {
                    if (!isset($this->dicClasses[$paramClassReflection->getName()])) {
                        continue;
                    }

                    if (!$paramClassReflection->hasNativeMethod('__construct')) {
                        continue;
                    }

                    $paramMethodOwner = $paramClassReflection->getNativeMethod('__construct')->getDeclaringClass()->getName();

                    $calls[] = new Call(
                        new Method($classReflection->getName(), '__construct'), // when this ctor is dead, the DIC ones are too
                        new Method($paramMethodOwner, '__construct'),
                        true,
                    );
                }
            }
        }

        return $calls;
    }

}

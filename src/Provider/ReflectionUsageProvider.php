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
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\TrinaryLogic;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnumBackedCase;
use ReflectionEnumUnitCase;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function array_filter;
use function array_key_first;
use function array_values;
use function count;
use function explode;
use function in_array;

final class ReflectionUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(
        bool $enabled
    )
    {
        $this->enabled = $enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        if ($node instanceof New_) {
            return $this->processNew($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function processNew(
        New_ $node,
        Scope $scope
    ): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        $args = $node->getArgs();

        if ($className === ReflectionMethod::class) {
            return $this->processReflectionMethodConstructor($args, $node, $scope);
        }

        if ($className === ReflectionProperty::class && count($args) === 2) {
            return $this->processClassMemberConstructor($args, $node, $scope, 'property');
        }

        if ($className === ReflectionClassConstant::class && count($args) === 2) {
            return $this->processClassMemberConstructor($args, $node, $scope, 'constant');
        }

        if (($className === ReflectionEnumUnitCase::class || $className === ReflectionEnumBackedCase::class) && count($args) === 2) {
            return $this->processClassMemberConstructor($args, $node, $scope, 'enumCase');
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function processStaticCall(
        StaticCall $node,
        Scope $scope
    ): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        if ($node->name instanceof Expr) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        $methodName = $node->name->toString();
        $args = $node->getArgs();

        if ($className === ReflectionMethod::class && $methodName === 'createFromMethodName' && count($args) === 1) {
            return $this->processClassMethodString($args[array_key_first($args)], $node, $scope);
        }

        return [];
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassMemberUsage>
     */
    private function processReflectionMethodConstructor(
        array $args,
        Node $node,
        Scope $scope
    ): array
    {
        if (count($args) === 2) {
            return $this->processClassMemberConstructor($args, $node, $scope, 'method');
        }

        if (count($args) === 1) {
            return $this->processClassMethodString($args[array_key_first($args)], $node, $scope);
        }

        return [];
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function processClassMethodString(
        Arg $arg,
        Node $node,
        Scope $scope
    ): array
    {
        $usages = [];

        foreach ($scope->getType($arg->value)->getConstantStrings() as $constantString) {
            $value = $constantString->getValue();

            $parts = explode('::', $value, 2);
            $ownerClass = $parts[0];
            $methodName = $parts[1] ?? null;

            if ($methodName === null) {
                continue;
            }
            $usage = $this->createMethodUsage($node, $scope, $ownerClass, $methodName);

            if ($usage !== null) {
                $usages[] = $usage;
            }
        }

        return $usages;
    }

    /**
     * @param array<Arg> $args
     * @param 'method'|'property'|'constant'|'enumCase' $memberType
     * @return list<ClassMemberUsage>
     */
    private function processClassMemberConstructor(
        array $args,
        Node $node,
        Scope $scope,
        string $memberType
    ): array
    {
        $argValues = array_values($args);
        $classArg = $argValues[0] ?? null;
        $memberArg = $argValues[1] ?? null;

        if ($classArg === null || $memberArg === null) {
            return [];
        }

        $classNames = [];

        foreach ($scope->getType($classArg->value)->getConstantStrings() as $constantString) {
            $classNames[] = $constantString->getValue();
        }

        foreach ($scope->getType($classArg->value)->getObjectClassNames() as $objectClassName) {
            $classNames[] = $objectClassName;
        }

        if ($classNames === []) {
            return [];
        }

        $memberNames = [];

        foreach ($scope->getType($memberArg->value)->getConstantStrings() as $constantString) {
            $memberNames[] = $constantString->getValue();
        }

        if ($memberNames === []) {
            return [];
        }

        $usages = [];

        foreach ($classNames as $ownerClass) {
            foreach ($memberNames as $memberName) {
                switch ($memberType) {
                    case 'method':
                        $usages[] = $this->createMethodUsage($node, $scope, $ownerClass, $memberName);
                        break;
                    case 'property':
                        $usages[] = $this->createPropertyUsage($node, $scope, $ownerClass, $memberName, AccessType::READ);
                        $usages[] = $this->createPropertyUsage($node, $scope, $ownerClass, $memberName, AccessType::WRITE);
                        break;
                    case 'constant':
                        $usages[] = $this->createConstantUsage($node, $scope, $ownerClass, $memberName);
                        break;
                    case 'enumCase':
                        $usages[] = $this->createEnumCaseUsage($node, $scope, $ownerClass, $memberName);
                        break;
                }
            }
        }

        return array_values(array_filter($usages, static fn (?ClassMemberUsage $usage): bool => $usage !== null));
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function processMethodCall(
        MethodCall $node,
        Scope $scope
    ): array
    {
        $callerType = $scope->getType($node->var);
        $methodNames = $this->getMethodNames($node, $scope);

        $usedConstants = [];
        $usedMethods = [];
        $usedEnumCases = [];
        $usedProperties = [];

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
                        $usedEnumCases = [
                            ...$usedEnumCases,
                            ...$this->extractEnumCasesUsedByReflection($genericClassName, $methodName, $node->getArgs(), $node, $scope),
                        ];
                        $usedProperties = [
                            ...$usedProperties,
                            ...$this->extractPropertiesUsedByReflection($genericClassName, $methodName, $node->getArgs(), $node, $scope),
                        ];
                    }
                }
            }
        }

        return array_values(array_filter([
            ...$usedConstants,
            ...$usedMethods,
            ...$usedEnumCases,
            ...$usedProperties,
        ], static fn (?ClassMemberUsage $usage): bool => $usage !== null));
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassConstantUsage|null>
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
     * @return list<ClassConstantUsage|null>
     */
    private function extractEnumCasesUsedByReflection(
        ?string $genericClassName,
        string $methodName,
        array $args,
        Node $node,
        Scope $scope
    ): array
    {
        $usedConstants = [];

        if ($methodName === 'getCases') {
            $usedConstants[] = $this->createEnumCaseUsage($node, $scope, $genericClassName, null);
        }

        if (($methodName === 'getCase') && count($args) === 1) {
            $firstArg = $args[array_key_first($args)];

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                $usedConstants[] = $this->createEnumCaseUsage($node, $scope, $genericClassName, $constantString->getValue());
            }
        }

        return $usedConstants;
    }

    /**
     * @param array<Arg> $args
     * @return list<ClassMethodUsage|null>
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
     * @param array<Arg> $args
     * @return list<ClassPropertyUsage|null>
     */
    private function extractPropertiesUsedByReflection(
        ?string $genericClassName,
        string $methodName,
        array $args,
        Node $node,
        Scope $scope
    ): array
    {
        $usedProperties = [];

        if (
            $methodName === 'getProperties'
            || $methodName === 'getDefaultProperties' // simplified, ideally should mark white only default properties
            || $methodName === 'getStaticProperties' // simplified, ideally should mark white only static properties
        ) {
            $usedProperties[] = $this->createPropertyUsage($node, $scope, $genericClassName, null, AccessType::READ);
            $usedProperties[] = $this->createPropertyUsage($node, $scope, $genericClassName, null, AccessType::WRITE); // ReflectionProperty is not generic, so we cannot track setValue call
        }

        if (in_array($methodName, ['getProperty', 'getStaticPropertyValue'], true) && count($args) >= 1) {
            $firstArg = $args[array_key_first($args)];

            foreach ($scope->getType($firstArg->value)->getConstantStrings() as $constantString) {
                $usedProperties[] = $this->createPropertyUsage($node, $scope, $genericClassName, $constantString->getValue(), AccessType::READ);
                $usedProperties[] = $this->createPropertyUsage($node, $scope, $genericClassName, $constantString->getValue(), AccessType::WRITE);
            }
        }

        return $usedProperties;
    }

    /**
     * @param NullsafeMethodCall|MethodCall|StaticCall|New_ $call
     * @return list<string>
     */
    private function getMethodNames(
        CallLike $call,
        Scope $scope
    ): array
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
    ): ?ClassConstantUsage
    {
        if ($className === null && $constantName === null) {
            return null;
        }

        return new ClassConstantUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassConstantRef(
                $className,
                $constantName,
                true,
                TrinaryLogic::createMaybe(),
            ),
        );
    }

    private function createEnumCaseUsage(
        Node $node,
        Scope $scope,
        ?string $className,
        ?string $enumCaseName
    ): ?ClassConstantUsage
    {
        if ($className === null && $enumCaseName === null) {
            return null;
        }

        return new ClassConstantUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassConstantRef(
                $className,
                $enumCaseName,
                false,
                TrinaryLogic::createYes(),
            ),
        );
    }

    private function createMethodUsage(
        Node $node,
        Scope $scope,
        ?string $className,
        ?string $methodName
    ): ?ClassMethodUsage
    {
        if ($className === null && $methodName === null) {
            return null;
        }

        return new ClassMethodUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassMethodRef(
                $className,
                $methodName,
                true,
            ),
        );
    }

    /**
     * @param AccessType::* $accessType
     */
    private function createPropertyUsage(
        Node $node,
        Scope $scope,
        ?string $className,
        ?string $propertyName,
        int $accessType
    ): ?ClassPropertyUsage
    {
        if ($className === null && $propertyName === null) {
            return null;
        }

        return new ClassPropertyUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassPropertyRef(
                $className,
                $propertyName,
                true,
            ),
            $accessType,
        );
    }

}

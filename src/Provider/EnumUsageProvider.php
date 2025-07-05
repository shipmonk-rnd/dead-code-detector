<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ShipMonk\PHPStan\DeadCode\Graph\EnumCaseRef;
use ShipMonk\PHPStan\DeadCode\Graph\EnumCaseUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use UnitEnum;
use function array_filter;
use function in_array;
use function is_int;
use function is_string;

class EnumUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if ($this->enabled === false) {
            return [];
        }

        if ($node instanceof StaticCall) {
            return $this->getTryFromUsages($node, $scope);
        }

        return [];
    }

    /**
     * @return list<EnumCaseUsage>
     */
    private function getTryFromUsages(
        StaticCall $staticCall,
        Scope $scope
    ): array
    {
        $methodNames = $this->getMethodNames($staticCall, $scope);
        $firstArgType = $this->getArgType($staticCall, $scope, 0);

        $callerType = $staticCall->class instanceof Expr
            ? $scope->getType($staticCall->class)
            : $scope->resolveTypeByName($staticCall->class);

        $typeNoNull = TypeCombinator::removeNull($callerType); // remove null to support nullsafe calls
        $typeNormalized = TypeUtils::toBenevolentUnion($typeNoNull); // extract possible calls even from Class|int
        $classReflections = $typeNormalized->getObjectTypeOrClassStringObjectType()->getObjectClassReflections();

        $result = [];

        foreach ($methodNames as $methodName) {
            if (!in_array($methodName, ['tryFrom', 'from', 'cases'], true)) {
                continue;
            }

            foreach ($classReflections as $classReflection) {
                if (!$classReflection->isEnum()) {
                    continue;
                }

                $valueToCaseMapping = $this->getValueToEnumCaseMapping($classReflection->getNativeReflection());
                $triedValues = $firstArgType->getConstantScalarValues() === []
                    ? [null]
                    : array_filter($firstArgType->getConstantScalarValues(), static fn ($value): bool => is_string($value) || is_int($value));

                foreach ($triedValues as $value) {
                    $enumCase = $value === null ? null : $valueToCaseMapping[$value] ?? null;
                    $result[] = new EnumCaseUsage(
                        UsageOrigin::createRegular($staticCall, $scope),
                        new EnumCaseRef($classReflection->getName(), $enumCase, false),
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string|null>
     */
    private function getMethodNames(
        StaticCall $call,
        Scope $scope
    ): array
    {
        if ($call->name instanceof Expr) {
            $possibleMethodNames = [];

            foreach ($scope->getType($call->name)->getConstantStrings() as $constantString) {
                $possibleMethodNames[] = $constantString->getValue();
            }

            return $possibleMethodNames === []
                ? [null] // unknown method name
                : $possibleMethodNames;
        }

        return [$call->name->name];
    }

    private function getArgType(
        StaticCall $staticCall,
        Scope $scope,
        int $position
    ): Type
    {
        $args = $staticCall->getArgs();

        if (isset($args[$position])) {
            return $scope->getType($args[$position]->value);
        }

        return new MixedType();
    }

    /**
     * @param ReflectionEnum<UnitEnum> $enumReflection
     * @return array<array-key, string>
     */
    private function getValueToEnumCaseMapping(ReflectionEnum $enumReflection): array
    {
        $mapping = [];

        foreach ($enumReflection->getCases() as $enumCaseReflection) {
            if (!$enumCaseReflection instanceof ReflectionEnumBackedCase) {
                continue;
            }

            $mapping[$enumCaseReflection->getBackingValue()] = $enumCaseReflection->getName();
        }

        return $mapping;
    }

}

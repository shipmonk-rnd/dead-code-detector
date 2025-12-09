<?php

use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use ShipMonk\CoverageGuard\Config;
use ShipMonk\CoverageGuard\Hierarchy\ClassMethodBlock;
use ShipMonk\CoverageGuard\Hierarchy\CodeBlock;
use ShipMonk\CoverageGuard\Rule\CoverageError;
use ShipMonk\CoverageGuard\Rule\CoverageRule;
use ShipMonk\CoverageGuard\Rule\InspectionContext;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Reflection\ReflectionHelper;

$config = new Config();
$config->addRule(new class implements CoverageRule {

    public function inspect(
        CodeBlock $codeBlock,
        InspectionContext $context
    ): ?CoverageError
    {
        if (!$codeBlock instanceof ClassMethodBlock) {
            return null;
        }

        if ($codeBlock->getExecutableLinesCount() < 5) {
            return null;
        }

        $classReflection = $context->getClassReflection();
        if ($classReflection === null) {
            return null;
        }

        $coverage = $codeBlock->getCoveragePercentage();
        $requiredCoverage = $this->getRequiredCoverage($classReflection);

        if ($codeBlock->getCoveragePercentage() < $requiredCoverage) {
            return CoverageError::create("Method <white>{$codeBlock->getMethodName()}</white> requires $requiredCoverage% coverage, but has only $coverage%.");
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $classReflection
     */
    private function getRequiredCoverage(ReflectionClass $classReflection): int
    {
        $isPoor = in_array($classReflection->getName(), [
            BackwardCompatibilityChecker::class,
            ReflectionHelper::class,
        ], true);

        $isCore = $classReflection->implementsInterface(MemberUsageProvider::class)
            || $classReflection->implementsInterface(Collector::class)
            || $classReflection->implementsInterface(Rule::class);

        return match (true) {
            $isCore => 80,
            $isPoor => 20,
            default => 50,
        };
    }

});

return $config;

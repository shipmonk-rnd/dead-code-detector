<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;

class UsageOriginDetector
{

    /**
     * Most of the time, this is the only implementation you need for ClassMemberUsage constructor
     */
    public function detectOrigin(Scope $scope): ?ClassMethodRef
    {
        if (!$scope->isInClass()) {
            return null;
        }

        if (!$scope->getFunction() instanceof MethodReflection) {
            return null;
        }

        return new ClassMethodRef(
            $scope->getClassReflection()->getName(),
            $scope->getFunction()->getName(),
            false,
        );
    }

}

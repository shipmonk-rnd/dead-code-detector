<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Graph;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

class UsageOriginDetector
{

    /**
     * Most of the time, this is the only implementation you need for ClassMemberUsage constructor
     */
    public function detectOrigin(Node $node, Scope $scope): UsageOrigin
    {
        return UsageOrigin::createRegular($node, $scope);
    }

}

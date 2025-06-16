<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;

/**
 * Extension point for marking constants/methods as used based on custom AST/Reflection logic.
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyAppUsageProvider
 *          tags:
 *              - shipmonk.deadCode.memberUsageProvider
 */
interface MemberUsageProvider
{

    /**
     * @return list<ClassMemberUsage>
     */
    public function getUsages(
        Node $node,
        Scope $scope
    ): array;

}

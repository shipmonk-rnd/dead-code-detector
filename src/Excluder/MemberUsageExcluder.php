<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;

/**
 * Extension point for unmarking usages as used.
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyAppUsageExcluder
 *          tags:
 *              - shipmonk.deadCode.memberUsageExcluder
 */
interface MemberUsageExcluder
{

    public function shouldExclude(ClassMemberUsage $usage, Node $node, Scope $scope): bool;

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Crate\Call;

/**
 * Extension point for emiting "virtual calls" based on AST
 *
 * Register in your phpstan.neon.dist:
 *
 * services:
 *    -
 *          class: App\MyShadowCallProvider
 *          tags:
 *              - shipmonk.deadCode.shadowCallProvider
 */
interface ShadowMethodCallProvider
{

    /**
     * @return list<Call>
     */
    public function getShadowMethodCalls(Node $node, Scope $scope): array;

}

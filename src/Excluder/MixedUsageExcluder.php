<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;

class MixedUsageExcluder implements MemberUsageExcluder
{

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function getIdentifier(): string
    {
        return 'usageOverMixed';
    }

    public function shouldExclude(
        ClassMemberUsage $usage,
        Node $node,
        Scope $scope
    ): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $usage->getMemberRef()->getClassName() === null;
    }

}

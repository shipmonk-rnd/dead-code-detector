<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Compatibility;

use LogicException;
use function array_map;
use function count;
use function get_class;
use function implode;

class BackwardCompatibilityChecker
{

    /**
     * @var list<object>
     */
    private array $servicesWithOldTag;

    /**
     * @param list<object> $servicesWithOldTag
     */
    public function __construct(array $servicesWithOldTag)
    {
        $this->servicesWithOldTag = $servicesWithOldTag;
    }

    public function check(): void
    {
        if (count($this->servicesWithOldTag) > 0) {
            $serviceClassNames = implode(' and ', array_map(static fn(object $service) => get_class($service), $this->servicesWithOldTag));
            $plural = count($this->servicesWithOldTag) > 1 ? 's' : '';
            $isAre = count($this->servicesWithOldTag) > 1 ? 'are' : 'is';

            throw new LogicException("Service$plural $serviceClassNames $isAre registered with old tag 'shipmonk.deadCode.entrypointProvider'. Please update the tag to 'shipmonk.deadCode.memberUsageProvider'.");
        }
    }

}

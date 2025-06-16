<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Compatibility;

use LogicException;
use function array_map;
use function count;
use function get_class;
use function implode;
use function var_export;

class BackwardCompatibilityChecker
{

    /**
     * @var list<object>
     */
    private array $servicesWithOldTag;

    private ?bool $trackMixedAccessParameterValue;

    /**
     * @param list<object> $servicesWithOldTag
     */
    public function __construct(
        array $servicesWithOldTag,
        ?bool $trackMixedAccessParameterValue
    )
    {
        $this->servicesWithOldTag = $servicesWithOldTag;
        $this->trackMixedAccessParameterValue = $trackMixedAccessParameterValue;
    }

    public function check(): void
    {
        if (count($this->servicesWithOldTag) > 0) {
            $serviceClassNames = implode(' and ', array_map(static fn (object $service) => get_class($service), $this->servicesWithOldTag));
            $plural = count($this->servicesWithOldTag) > 1 ? 's' : '';
            $isAre = count($this->servicesWithOldTag) > 1 ? 'are' : 'is';

            throw new LogicException("Service$plural $serviceClassNames $isAre registered with old tag 'shipmonk.deadCode.entrypointProvider'. Please update the tag to 'shipmonk.deadCode.memberUsageProvider'.");
        }

        if ($this->trackMixedAccessParameterValue !== null) {
            $newValue = var_export(!$this->trackMixedAccessParameterValue, true);
            throw new LogicException("Using deprecated parameter 'trackMixedAccess', please use 'parameters.shipmonkDeadCode.usageExcluders.usageOverMixed.enabled: $newValue' instead.");
        }
    }

}

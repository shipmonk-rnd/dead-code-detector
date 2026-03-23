<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Compatibility;

use LogicException;
use function array_map;
use function count;
use function implode;
use function var_export;

final class BackwardCompatibilityChecker
{

    /**
     * @param list<object> $servicesWithOldTag
     */
    public function __construct(
        private readonly array $servicesWithOldTag,
        private readonly ?bool $trackMixedAccessParameterValue,
    )
    {
    }

    public function check(): void
    {
        if (count($this->servicesWithOldTag) > 0) {
            $serviceClassNames = implode(' and ', array_map(static fn (object $service) => $service::class, $this->servicesWithOldTag));
            $plural = count($this->servicesWithOldTag) > 1 ? 's' : '';
            $isAre = count($this->servicesWithOldTag) > 1 ? 'are' : 'is';

            throw new LogicException("Service$plural $serviceClassNames $isAre registered with old tag 'shipmonk.deadCode.entrypointProvider'. Please update the tag to 'shipmonk.deadCode.memberUsageProvider'.");
        }

        if ($this->trackMixedAccessParameterValue !== null) {
            $newValue = var_export(!$this->trackMixedAccessParameterValue, return: true);
            throw new LogicException("Using deprecated parameter 'trackMixedAccess', please use 'parameters.shipmonkDeadCode.usageExcluders.usageOverMixed.enabled: $newValue' instead.");
        }
    }

}

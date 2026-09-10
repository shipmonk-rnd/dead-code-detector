<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

interface ActivatableUsageProvider
{

    public function isEnabled(): bool;

}

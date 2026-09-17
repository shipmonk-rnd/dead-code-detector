<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

interface ActivatableUsageProvider extends MemberUsageProvider
{

    public function isEnabled(): bool;

}

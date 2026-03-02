<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\DependencyInjection\Container;
use ReflectionMethod;

final class PhpStanUsageProvider extends ReflectionBasedMemberUsageProvider
{

    public function __construct(
        private readonly bool $enabled,
        private readonly Container $container,
    )
    {
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->isConstructorCallInPhpStanDic($method);
    }

    private function isConstructorCallInPhpStanDic(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$method->isConstructor()) {
            return null;
        }

        if ($this->container->findServiceNamesByType($method->getDeclaringClass()->getName()) !== []) {
            return VirtualUsageData::withNote('Constructor call from PHPStan DI container');
        }

        return null;
    }

}

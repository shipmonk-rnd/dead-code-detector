<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\DependencyInjection\Container;
use ReflectionMethod;

class PhpStanUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private bool $enabled;

    private Container $container;

    public function __construct(bool $enabled, Container $container)
    {
        $this->enabled = $enabled;
        $this->container = $container;
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->isConstructorCallInPhpStanDic($method);
    }

    private function isConstructorCallInPhpStanDic(ReflectionMethod $method): bool
    {
        if (!$method->isConstructor()) {
            return false;
        }

        return $this->container->findServiceNamesByType($method->getDeclaringClass()->getName()) !== [];
    }

}

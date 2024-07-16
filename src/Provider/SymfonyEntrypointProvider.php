<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionMethod;
use const PHP_VERSION_ID;

class SymfonyEntrypointProvider implements EntrypointProvider
{

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $methodName = $method->getName();

        return $method->getDeclaringClass()->implementsInterface('Symfony\Component\EventDispatcher\EventSubscriberInterface')
            || (PHP_VERSION_ID >= 80_000 && $method->getAttributes('Symfony\Contracts\Service\Attribute\Required') !== [])
            || (PHP_VERSION_ID >= 80_000 && $method->getAttributes('Symfony\Component\Routing\Attribute\Route') !== [])
            || $this->isProbablySymfonyListener($methodName);
    }

    /**
     * Ideally, we would need to parse DIC xml to know this for sure just like phpstan-symfony does.
     */
    private function isProbablySymfonyListener(string $methodName): bool
    {
        return $methodName === 'onKernelResponse'
            || $methodName === 'onKernelException'
            || $methodName === 'onKernelRequest'
            || $methodName === 'onConsoleError'
            || $methodName === 'onConsoleCommand'
            || $methodName === 'onConsoleSignal'
            || $methodName === 'onConsoleTerminate';
    }

}

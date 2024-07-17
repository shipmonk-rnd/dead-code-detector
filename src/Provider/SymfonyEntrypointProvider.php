<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionAttribute;
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
        $class = $method->getDeclaringClass();

        return $class->implementsInterface('Symfony\Component\EventDispatcher\EventSubscriberInterface')
            || (PHP_VERSION_ID >= 8_00_00 && $class->getAttributes('Symfony\Component\EventDispatcher\Attribute\AsEventListener') !== [])
            || (PHP_VERSION_ID >= 8_00_00 && $method->getAttributes('Symfony\Component\EventDispatcher\Attribute\AsEventListener') !== [])
            || (PHP_VERSION_ID >= 8_00_00 && $method->getAttributes('Symfony\Contracts\Service\Attribute\Required') !== [])
            || (PHP_VERSION_ID >= 8_00_00 && $method->getAttributes('Symfony\Component\Routing\Attribute\Route', ReflectionAttribute::IS_INSTANCEOF) !== [])
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

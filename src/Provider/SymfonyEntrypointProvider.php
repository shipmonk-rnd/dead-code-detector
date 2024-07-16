<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

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

        return $method->getDeclaringClass()->implementsInterface(EventSubscriberInterface::class)
            || $method->getAttributes(Required::class) !== []
            || $method->getAttributes(Route::class) !== []
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

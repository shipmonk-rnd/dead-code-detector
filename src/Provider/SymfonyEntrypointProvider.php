<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ReflectionProvider;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use const PHP_VERSION_ID;

class SymfonyEntrypointProvider implements EntrypointProvider
{

    private ReflectionProvider $reflectionProvider;

    private bool $enabled;

    public function __construct(ReflectionProvider $reflectionProvider, bool $enabled)
    {
        $this->reflectionProvider = $reflectionProvider;
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
            || $this->hasAttribute($class, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')
            || $this->hasAttribute($method, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')
            || $this->hasAttribute($method, 'Symfony\Contracts\Service\Attribute\Required')
            || $this->hasAttribute($method, 'Symfony\Component\Routing\Attribute\Route', ReflectionAttribute::IS_INSTANCEOF)
            || $this->hasAttribute($method, 'Symfony\Component\Routing\Annotation\Route', ReflectionAttribute::IS_INSTANCEOF)
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

    /**
     * @param ReflectionClass<object>|ReflectionMethod $classOrMethod
     * @param ReflectionAttribute::IS_*|0 $flags
     */
    private function hasAttribute(Reflector $classOrMethod, string $attributeClass, int $flags = 0): bool
    {
        if (PHP_VERSION_ID < 8_00_00) {
            return false;
        }

        if ($classOrMethod->getAttributes($attributeClass) !== []) {
            return true;
        }

        return $this->reflectionProvider->hasClass($attributeClass) // prevent https://github.com/phpstan/phpstan/issues/9618
            && $classOrMethod->getAttributes($attributeClass, $flags) !== [];
    }

}

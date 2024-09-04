<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Symfony\ServiceMapFactory;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Reflection\ClassHierarchy;
use const PHP_VERSION_ID;

class SymfonyEntrypointProvider implements EntrypointProvider
{

    private ReflectionProvider $reflectionProvider;

    private ClassHierarchy $classHierarchy;

    private bool $enabled;

    /**
     * @var array<string, true>
     */
    private array $dicClasses = [];

    public function __construct(
        ReflectionProvider $reflectionProvider,
        ClassHierarchy $classHierarchy,
        ?ServiceMapFactory $serviceMapFactory,
        ?bool $enabled
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->classHierarchy = $classHierarchy;
        $this->enabled = $enabled ?? $this->isSymfonyInstalled();

        if ($serviceMapFactory !== null) {
            foreach ($serviceMapFactory->create()->getServices() as $service) { // @phpstan-ignore phpstanApi.method, phpstanApi.method
                $dicClass = $service->getClass(); // @phpstan-ignore phpstanApi.method

                if ($dicClass === null) {
                    continue;
                }

                $this->dicClasses[$dicClass] = true;
            }
        }
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
            || $this->isConstructorCalledBySymfonyDic($method)
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

        try {
            /** @throws IdentifierNotFound */
            return $classOrMethod->getAttributes($attributeClass, $flags) !== [];
        } catch (IdentifierNotFound $e) {
            return false; // prevent https://github.com/phpstan/phpstan/issues/9618
        }
    }

    private function isConstructorCalledBySymfonyDic(ReflectionMethod $method): bool
    {
        if (!$method->isConstructor()) {
            return false;
        }

        $declaringClass = $method->getDeclaringClass()->getName();

        if (isset($this->dicClasses[$declaringClass])) {
            return true;
        }

        foreach ($this->classHierarchy->getClassDescendants($declaringClass) as $descendant) {
            $descendantReflection = $this->reflectionProvider->getClass($descendant);

            if (!$descendantReflection->hasConstructor()) {
                continue;
            }

            if ($descendantReflection->getConstructor()->getDeclaringClass()->getName() === $descendantReflection->getName()) {
                return false;
            }

            if (isset($this->dicClasses[$descendant])) {
                return true;
            }
        }

        return false;
    }

    private function isSymfonyInstalled(): bool
    {
        return InstalledVersions::isInstalled('symfony/event-dispatcher')
            || InstalledVersions::isInstalled('symfony/routing')
            || InstalledVersions::isInstalled('symfony/contracts');
    }

}

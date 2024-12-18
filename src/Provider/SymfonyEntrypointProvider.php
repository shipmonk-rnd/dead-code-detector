<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Symfony\ServiceMapFactory;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use const PHP_VERSION_ID;

class SymfonyEntrypointProvider implements MethodEntrypointProvider
{

    private bool $enabled;

    /**
     * @var array<string, true>
     */
    private array $dicClasses = [];

    public function __construct(
        ?ServiceMapFactory $serviceMapFactory,
        ?bool $enabled
    )
    {
        $this->enabled = $enabled ?? $this->isSymfonyInstalled();

        if ($serviceMapFactory !== null) {
            foreach ($serviceMapFactory->create()->getServices() as $service) { // @phpstan-ignore phpstanApi.method
                $dicClass = $service->getClass();

                if ($dicClass === null) {
                    continue;
                }

                $this->dicClasses[$dicClass] = true;
            }
        }
    }

    public function getEntrypoints(ClassReflection $classReflection): array
    {
        $nativeReflection = $classReflection->getNativeReflection();
        $className = $classReflection->getName();

        $entrypoints = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->isConstructor() && isset($this->dicClasses[$className])) {
                $entrypoints[] = $classReflection->getNativeMethod($method->getName());
            }

            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            if ($this->isEntrypointMethod($method)) {
                $entrypoints[] = $classReflection->getNativeMethod($method->getName());
            }
        }

        return $entrypoints;
    }

    public function isEntrypointMethod(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $methodName = $method->getName();
        $class = $method->getDeclaringClass();

        return $class->implementsInterface('Symfony\Component\EventDispatcher\EventSubscriberInterface')
            || ($class->isSubclassOf('Symfony\Component\HttpKernel\Bundle\Bundle') && $method->isConstructor())
            || $this->hasAttribute($class, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')
            || $this->hasAttribute($method, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')
            || $this->hasAttribute($method, 'Symfony\Contracts\Service\Attribute\Required')
            || ($this->hasAttribute($class, 'Symfony\Component\Console\Attribute\AsCommand') && $method->isConstructor())
            || ($this->hasAttribute($class, 'Symfony\Component\HttpKernel\Attribute\AsController') && $method->isConstructor())
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

        try {
            /** @throws IdentifierNotFound */
            return $classOrMethod->getAttributes($attributeClass, $flags) !== [];
        } catch (IdentifierNotFound $e) {
            return false; // prevent https://github.com/phpstan/phpstan/issues/9618
        }
    }

    private function isSymfonyInstalled(): bool
    {
        return InstalledVersions::isInstalled('symfony/event-dispatcher')
            || InstalledVersions::isInstalled('symfony/routing')
            || InstalledVersions::isInstalled('symfony/contracts')
            || InstalledVersions::isInstalled('symfony/console')
            || InstalledVersions::isInstalled('symfony/http-kernel');
    }

}

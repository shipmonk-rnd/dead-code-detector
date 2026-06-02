<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use LogicException;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Application\UI\SignalReceiver;
use Nette\ComponentModel\Container;
use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Nette\SmartObject;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use function class_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function lcfirst;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;
use function substr;
use function ucfirst;
use const PREG_SET_ORDER;

final class NetteUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private readonly ReflectionProvider $reflectionProvider;

    private readonly bool $enabled;

    /**
     * @var array<string, array<string, true>>
     */
    private array $smartObjectCache = [];

    /**
     * Classes declaring a constructor invoked by the Nette DI container, collected from NEON `services:` config.
     *
     * @var array<class-string, true>
     */
    private array $serviceClasses = [];

    /**
     * @param list<string> $containerNeonPaths Paths to NEON files describing the DI container services
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        ?bool $enabled,
        array $containerNeonPaths,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled ?? $this->isNetteInstalled();

        if ($this->enabled && $containerNeonPaths !== []) {
            if (!class_exists(Neon::class)) {
                throw new LogicException('Install nette/neon to use the containerNeonPaths option of the Nette usage provider');
            }

            foreach ($containerNeonPaths as $containerNeonPath) {
                $this->loadServicesFromNeon($containerNeonPath);
            }
        }
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        $methodName = $method->getName();
        $class = $method->getDeclaringClass();
        $className = $class->getName();
        $reflection = $this->reflectionProvider->getClass($className);

        $magicUsage = $this->isNetteMagic($reflection, $methodName);

        if ($magicUsage !== null) {
            return $magicUsage;
        }

        if ($methodName === '__construct' && isset($this->serviceClasses[$className])) {
            return VirtualUsageData::withNote('Registered as a service in Nette DI container');
        }

        return null;
    }

    private function isNetteMagic(
        ClassReflection $reflection,
        string $methodName,
    ): ?VirtualUsageData
    {
        if (
            $reflection->is(SignalReceiver::class)
            && str_starts_with($methodName, 'handle')
        ) {
            return VirtualUsageData::withNote('Signal handler method');
        }

        if (
            $reflection->is(Container::class)
            && str_starts_with($methodName, 'createComponent')
        ) {
            return VirtualUsageData::withNote('Component factory method');
        }

        if (
            $reflection->is(Control::class)
            && str_starts_with($methodName, 'render')
        ) {
            return VirtualUsageData::withNote('Render method');
        }

        if (
            $reflection->is(Presenter::class) && str_starts_with($methodName, 'action')
        ) {
            return VirtualUsageData::withNote('Presenter action method');
        }

        if (
            $reflection->is(Presenter::class) && str_starts_with($methodName, 'inject')
        ) {
            return VirtualUsageData::withNote('Presenter inject method');
        }

        if (
            $reflection->hasTraitUse(SmartObject::class)
        ) {
            if (str_starts_with($methodName, 'is')) {
                /** @var string $name cannot be false */
                $name = substr($methodName, 2);

            } elseif (str_starts_with($methodName, 'get') || str_starts_with($methodName, 'set')) {
                /** @var string $name cannot be false */
                $name = substr($methodName, 3);

            } else {
                $name = null;
            }

            if ($name !== null) {
                $name = lcfirst($name);
                $property = $this->getMagicProperties($reflection)[$name] ?? null;

                if ($property !== null) {
                    return VirtualUsageData::withNote('Access method for magic property ' . $name);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     *
     * @see ObjectHelpers::getMagicProperties() Modified to use static reflection
     */
    private function getMagicProperties(ClassReflection $reflection): array
    {
        $rc = $reflection->getNativeReflection();
        $class = $rc->getName();

        if (isset($this->smartObjectCache[$class])) {
            return $this->smartObjectCache[$class];
        }

        preg_match_all(
            '~^  [ \t*]*  @property(|-read|-write|-deprecated)  [ \t]+  [^\s$]+  [ \t]+  \$  (\w+)  ()~mx',
            (string) $rc->getDocComment(),
            $matches,
            PREG_SET_ORDER,
        );

        $props = [];

        foreach ($matches as [, $type, $name]) {
            $uname = ucfirst($name);
            $write = $type !== '-read'
                && $rc->hasMethod($nm = 'set' . $uname)
                && ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic(); // @phpstan-ignore missingType.checkedException
            $read = $type !== '-write'
                && ($rc->hasMethod($nm = 'get' . $uname) || $rc->hasMethod($nm = 'is' . $uname))
                && ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic(); // @phpstan-ignore missingType.checkedException

            if ($read || $write) {
                $props[$name] = true;
            }
        }

        foreach ($reflection->getTraits() as $trait) {
            $props += $this->getMagicProperties($trait);
        }

        foreach ($reflection->getParents() as $parent) {
            $props += $this->getMagicProperties($parent);
        }

        $this->smartObjectCache[$class] = $props;
        return $props;
    }

    /**
     * Extracts the FQCNs the container instantiates from a NEON `services:` block.
     *
     * @return list<class-string>
     */
    public static function findServiceClassesInNeon(string $neonContent): array
    {
        $decoded = Neon::decode($neonContent);

        if (!is_array($decoded) || !isset($decoded['services']) || !is_array($decoded['services'])) {
            return [];
        }

        $classes = [];

        foreach ($decoded['services'] as $entry) {
            $class = self::resolveServiceClass($entry);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function loadServicesFromNeon(string $containerNeonPath): void
    {
        $contents = file_get_contents($containerNeonPath);

        if ($contents === false) {
            throw new LogicException(sprintf('Nette container file %s does not exist or is not readable', $containerNeonPath));
        }

        foreach (self::findServiceClassesInNeon($contents) as $class) {
            if ($this->reflectionProvider->hasClass($class)) {
                $this->markServiceClass($class);
            }
        }
    }

    /**
     * @param class-string $class
     */
    private function markServiceClass(string $class): void
    {
        $classReflection = $this->reflectionProvider->getClass($class);

        $this->markConstructorDeclaringClass($classReflection);

        // Automatically generated factory interfaces: Nette DI generates a class implementing
        // the interface whose create() returns `new ReturnType(...)`. That return type's
        // constructor also needs to be marked as used - there is no statically visible `new`.
        if (!$classReflection->isInterface() || !$classReflection->hasNativeMethod('create')) {
            return;
        }

        $createMethod = $classReflection->getNativeMethod('create');

        foreach ($createMethod->getVariants() as $variant) {
            foreach ($variant->getReturnType()->getObjectClassNames() as $returnedClass) {
                if ($this->reflectionProvider->hasClass($returnedClass)) {
                    $this->markConstructorDeclaringClass($this->reflectionProvider->getClass($returnedClass));
                }
            }
        }
    }

    /**
     * Records the class that actually declares the constructor, so a service that inherits its
     * constructor credits the declaring ancestor (matching where the dead-code rule reports it).
     */
    private function markConstructorDeclaringClass(ClassReflection $classReflection): void
    {
        if (!$classReflection->hasConstructor()) {
            return;
        }

        $this->serviceClasses[$classReflection->getConstructor()->getDeclaringClass()->getName()] = true;
    }

    /**
     * @return class-string|null
     */
    private static function resolveServiceClass(mixed $value): ?string
    {
        if (is_string($value)) {
            return self::extractClassName($value);
        }

        if ($value instanceof Entity && is_string($value->value)) {
            return self::extractClassName($value->value);
        }

        if (is_array($value)) {
            // Imported services are provided at runtime (e.g. via addImportedDefinition), not instantiated by the container.
            if (($value['imported'] ?? null) === true) {
                return null;
            }

            if (isset($value['create'])) {
                return self::resolveServiceClass($value['create']);
            }

            if (isset($value['factory'])) { // legacy alias of create
                return self::resolveServiceClass($value['factory']);
            }

            if (isset($value['class'])) {
                return self::resolveServiceClass($value['class']);
            }

            if (isset($value['type'])) {
                return self::resolveServiceClass($value['type']);
            }

            if (isset($value['implement'])) {
                return self::resolveServiceClass($value['implement']);
            }
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    private static function extractClassName(string $value): ?string
    {
        // Reject @serviceName::method references, callables, values with spaces etc.
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $value) !== 1) {
            return null;
        }

        /** @var class-string $normalised */
        $normalised = ltrim($value, '\\');

        return $normalised;
    }

    private function isNetteInstalled(): bool
    {
        return InstalledVersions::isInstalled('nette/application')
            || InstalledVersions::isInstalled('nette/component-model')
            || InstalledVersions::isInstalled('nette/utils');
    }

}

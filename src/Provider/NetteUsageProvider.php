<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Application\UI\SignalReceiver;
use Nette\ComponentModel\Container;
use Nette\SmartObject;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClass;
use ReflectionMethod;
use function lcfirst;
use function preg_match_all;
use function strpos;
use function substr;
use function ucfirst;
use const PREG_SET_ORDER;

class NetteUsageProvider extends SimpleMethodUsageProvider
{

    private ReflectionProvider $reflectionProvider;

    private bool $enabled;

    /**
     * @var array<string, array<string, true>>
     */
    private array $smartObjectCache = [];

    public function __construct(
        ReflectionProvider $reflectionProvider,
        ?bool $enabled
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled ?? $this->isNetteInstalled();
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $methodName = $method->getName();
        $class = $method->getDeclaringClass();
        $className = $class->getName();
        $reflection = $this->reflectionProvider->getClass($className);

        return $this->isNetteMagic($reflection, $methodName);
    }

    private function isNetteMagic(ClassReflection $reflection, string $methodName): bool
    {
        if (
            $reflection->is(SignalReceiver::class)
            && strpos($methodName, 'handle') === 0
        ) {
            return true;
        }

        if (
            $reflection->is(Container::class)
            && strpos($methodName, 'createComponent') === 0
        ) {
            return true;
        }

        if (
            $reflection->is(Control::class)
            && strpos($methodName, 'render') === 0
        ) {
            return true;
        }

        if (
            $reflection->is(Presenter::class)
            && (
                strpos($methodName, 'action') === 0
                || strpos($methodName, 'inject') === 0
            )
        ) {
            return true;
        }

        if (
            $reflection->hasTraitUse(SmartObject::class)
        ) {
            if (strpos($methodName, 'is') === 0) {
                /** @var string $name cannot be false */
                $name = substr($methodName, 2);

            } elseif (strpos($methodName, 'get') === 0 || strpos($methodName, 'set') === 0) {
                /** @var string $name cannot be false */
                $name = substr($methodName, 3);

            } else {
                $name = null;
            }

            if ($name !== null) {
                $name = lcfirst($name);
                $property = $this->getMagicProperties($reflection->getNativeReflection())[$name] ?? null;

                if ($property !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $rc
     * @return array<string, true>
     * @see ObjectHelpers::getMagicProperties() Modified to use static reflection
     */
    private function getMagicProperties(ReflectionClass $rc): array
    {
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

        foreach ($rc->getTraits() as $trait) {
            $props += $this->getMagicProperties($trait);
        }

        $parent = $rc->getParentClass();

        if ($parent !== false) {
            $props += $this->getMagicProperties($parent);
        }

        $this->smartObjectCache[$class] = $props;
        return $props;
    }

    private function isNetteInstalled(): bool
    {
        return InstalledVersions::isInstalled('nette/application')
            || InstalledVersions::isInstalled('nette/component-model')
            || InstalledVersions::isInstalled('nette/utils');
    }

}
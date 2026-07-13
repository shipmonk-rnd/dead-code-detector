<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use LogicException;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Composer\ComposerIntrospector;
use function explode;
use function is_array;
use function is_file;
use function is_string;
use function ltrim;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Detects static methods referenced as PHP callbacks in the scripts section of composer.json,
 * e.g. "post-install-cmd": "MyVendor\\MyClass::postInstall"
 *
 * @see https://getcomposer.org/doc/articles/scripts.md#defining-scripts
 */
final class ComposerUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private readonly ReflectionProvider $reflectionProvider;

    private readonly ComposerIntrospector $composerIntrospector;

    /**
     * declaring class => [method => note]
     *
     * @var array<string, array<string, string>>
     */
    private array $scriptCalls = [];

    public function __construct(
        ReflectionProvider $reflectionProvider,
        ComposerIntrospector $composerIntrospector,
        bool $enabled,
        ?string $composerJsonPath,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->composerIntrospector = $composerIntrospector;
        $this->loadScriptCallbacks($enabled, $composerJsonPath);
    }

    private function loadScriptCallbacks(
        bool $enabled,
        ?string $composerJsonPath,
    ): void
    {
        if (!$enabled) {
            return;
        }

        if ($composerJsonPath === null) {
            $autodetectedPath = $this->composerIntrospector->autodetectComposerJsonPath();

            if ($autodetectedPath !== null) {
                $this->extractScriptCallbacks($autodetectedPath);
            }

            return;
        }

        if (!is_file($composerJsonPath)) {
            throw new LogicException(sprintf('Composer json %s does not exist', $composerJsonPath));
        }

        $this->extractScriptCallbacks($composerJsonPath);
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        $note = $this->scriptCalls[$method->getDeclaringClass()->getName()][$method->getName()] ?? null;

        return $note === null
            ? null
            : VirtualUsageData::withNote($note);
    }

    private function extractScriptCallbacks(string $composerJsonPath): void
    {
        $composerJsonData = $this->composerIntrospector->parseComposerJson($composerJsonPath);
        $scripts = $composerJsonData['scripts'] ?? [];

        if (!is_array($scripts)) {
            return;
        }

        foreach ($scripts as $scriptName => $listeners) {
            if (!is_array($listeners)) {
                $listeners = [$listeners];
            }

            foreach ($listeners as $listener) {
                if (!is_string($listener) || !$this->isPhpScript($listener)) {
                    continue;
                }

                [$className, $methodName] = explode('::', $listener, 2); // @phpstan-ignore offsetAccess.notFound
                $className = ltrim($className, '\\');

                $this->registerScriptCallback($className, $methodName, sprintf("Composer script '%s' in %s", $scriptName, $composerJsonPath));
            }
        }
    }

    private function registerScriptCallback(
        string $className,
        string $methodName,
        string $note,
    ): void
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return;
        }

        $nativeClassReflection = $this->reflectionProvider->getClass($className)->getNativeReflection();

        if (!$nativeClassReflection->hasMethod($methodName)) {
            return;
        }

        $nativeMethodReflection = $nativeClassReflection->getMethod($methodName); // @phpstan-ignore missingType.checkedException (guarded by hasMethod above)

        $this->scriptCalls[$nativeMethodReflection->getDeclaringClass()->getName()][$nativeMethodReflection->getName()] = $note;
    }

    /**
     * Mirrors Composer\EventDispatcher\EventDispatcher::isPhpScript, listeners starting with @ are script/command references.
     */
    private function isPhpScript(string $listener): bool
    {
        return !str_starts_with($listener, '@')
            && !str_contains($listener, ' ')
            && str_contains($listener, '::');
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use LogicException;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use function array_filter;
use function array_keys;
use function count;
use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_last_error;
use function ltrim;
use function reset;
use function sprintf;
use function str_contains;
use function str_starts_with;
use const JSON_ERROR_NONE;

/**
 * Detects static methods referenced as PHP callbacks in the scripts section of composer.json,
 * e.g. "post-install-cmd": "MyVendor\\MyClass::postInstall"
 *
 * @see https://getcomposer.org/doc/articles/scripts.md#defining-scripts
 */
final class ComposerUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private readonly ReflectionProvider $reflectionProvider;

    /**
     * declaring class => [method => note]
     *
     * @var array<string, array<string, string>>
     */
    private array $scriptCalls = [];

    public function __construct(
        ReflectionProvider $reflectionProvider,
        bool $enabled,
        ?string $composerJsonPath,
    )
    {
        $this->reflectionProvider = $reflectionProvider;

        if ($enabled) {
            if ($composerJsonPath === null) {
                $autodetectedPath = $this->autodetectComposerJsonPath();

                if ($autodetectedPath !== null) {
                    $this->extractScriptCallbacks($autodetectedPath);
                }
            } else {
                if (!is_file($composerJsonPath)) {
                    throw new LogicException(sprintf('Composer json %s does not exist', $composerJsonPath));
                }

                $this->extractScriptCallbacks($composerJsonPath);
            }
        }
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
        $composerJsonRawData = file_get_contents($composerJsonPath);

        if ($composerJsonRawData === false) {
            return;
        }

        $composerJsonData = json_decode($composerJsonRawData, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($composerJsonData)) {
            return;
        }

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

    private function autodetectComposerJsonPath(): ?string
    {
        $vendorDirs = array_filter(array_keys(ClassLoader::getRegisteredLoaders()), static function (string $vendorDir): bool {
            return !str_starts_with($vendorDir, 'phar://');
        });

        if (count($vendorDirs) !== 1) {
            return null;
        }

        $composerJsonPath = reset($vendorDirs) . '/../composer.json';

        if (!is_file($composerJsonPath)) {
            return null;
        }

        return $composerJsonPath;
    }

}

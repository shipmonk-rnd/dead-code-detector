<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
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
final class ComposerUsageProvider implements MemberUsageProvider
{

    private readonly bool $enabled;

    /**
     * class => [method => note]
     *
     * @var array<string, array<string, string>>
     */
    private array $scriptCalls = [];

    /**
     * @param list<string> $composerJsonPaths
     */
    public function __construct(
        bool $enabled,
        array $composerJsonPaths,
    )
    {
        $this->enabled = $enabled;

        if ($enabled && $composerJsonPaths === []) {
            $autodetectedPath = $this->autodetectComposerJsonPath();

            if ($autodetectedPath !== null) {
                $this->extractScriptCallbacks($autodetectedPath);
            }
        } elseif ($enabled) {
            foreach ($composerJsonPaths as $composerJsonPath) {
                if (!is_file($composerJsonPath)) {
                    throw new LogicException(sprintf('Composer json %s does not exist', $composerJsonPath));
                }

                $this->extractScriptCallbacks($composerJsonPath);
            }
        }
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled || $this->scriptCalls === []) {
            return [];
        }

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return $this->getScriptUsages($node->getClassReflection());
        }

        return [];
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getScriptUsages(ClassReflection $classReflection): array
    {
        $usages = [];

        foreach ($this->scriptCalls[$classReflection->getName()] ?? [] as $methodName => $note) {
            if (!$classReflection->hasNativeMethod($methodName)) {
                continue;
            }

            $methodReflection = $classReflection->getNativeMethod($methodName);

            $usages[] = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
                new ClassMethodRef(
                    $methodReflection->getDeclaringClass()->getName(),
                    $methodReflection->getName(),
                    possibleDescendant: false,
                ),
            );
        }

        return $usages;
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

                $this->scriptCalls[$className][$methodName] = sprintf("Composer script '%s' in %s", $scriptName, $composerJsonPath);
            }
        }
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

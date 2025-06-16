<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use Composer\Autoload\ClassLoader;
use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use function array_filter;
use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function is_array;
use function is_file;
use function json_decode;
use function json_last_error;
use function preg_match;
use function realpath;
use function reset;
use function strpos;
use const JSON_ERROR_NONE;

class TestsUsageExcluder implements MemberUsageExcluder
{

    private ReflectionProvider $reflectionProvider;

    /**
     * @var list<string>
     */
    private array $devPaths = [];

    private bool $enabled;

    /**
     * @param list<string>|null $devPaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        bool $enabled,
        ?array $devPaths
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->enabled = $enabled;

        if ($devPaths !== null) {
            foreach ($devPaths as $devPath) {
                $this->devPaths[] = $this->realpath($devPath);
            }
        } else {
            $this->devPaths = $this->autodetectComposerDevPaths();
        }
    }

    public function getIdentifier(): string
    {
        return 'tests';
    }

    public function shouldExclude(
        ClassMemberUsage $usage,
        Node $node,
        Scope $scope
    ): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->isWithinDevPaths($this->realpath($scope->getFile())) === true
            && $this->isWithinDevPaths($this->getDeclarationFile($usage->getMemberRef()->getClassName())) === false;
    }

    private function isWithinDevPaths(?string $filePath): ?bool
    {
        if ($filePath === null) {
            return null;
        }

        foreach ($this->devPaths as $devPath) {
            if (strpos($filePath, $devPath) === 0) {
                return true;
            }
        }

        return false;
    }

    private function getDeclarationFile(?string $className): ?string
    {
        if ($className === null) {
            return null;
        }

        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $filePath = $this->reflectionProvider->getClass($className)->getFileName();

        if ($filePath === null) {
            return null;
        }

        return $this->realpath($filePath);
    }

    /**
     * @return list<string>
     */
    private function autodetectComposerDevPaths(): array
    {
        $vendorDirs = array_filter(array_keys(ClassLoader::getRegisteredLoaders()), static function (string $vendorDir): bool {
            return strpos($vendorDir, 'phar://') === false;
        });

        if (count($vendorDirs) !== 1) {
            return [];
        }

        $vendorDir = reset($vendorDirs);
        $composerJsonPath = $vendorDir . '/../composer.json';

        $composerJsonData = $this->parseComposerJson($composerJsonPath);
        $basePath = dirname($composerJsonPath);

        return [
            ...$this->extractAutoloadPaths($basePath, $composerJsonData['autoload-dev']['psr-0'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $composerJsonData['autoload-dev']['psr-4'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $composerJsonData['autoload-dev']['files'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $composerJsonData['autoload-dev']['classmap'] ?? []),
        ];
    }

    /**
     * @return array{
     *     autoload-dev?: array{
     *          psr-0?: array<string, string|string[]>,
     *          psr-4?: array<string, string|string[]>,
     *          files?: string[],
     *          classmap?: string[],
     *     }
     * }
     */
    private function parseComposerJson(string $composerJsonPath): array
    {
        if (!is_file($composerJsonPath)) {
            return [];
        }

        $composerJsonRawData = file_get_contents($composerJsonPath);

        if ($composerJsonRawData === false) {
            return [];
        }

        $composerJsonData = json_decode($composerJsonRawData, true);

        $jsonError = json_last_error();

        if ($jsonError !== JSON_ERROR_NONE) {
            return [];
        }

        return $composerJsonData; // @phpstan-ignore-line ignore mixed returned
    }

    /**
     * @param array<string|array<string>> $autoload
     * @return list<string>
     */
    private function extractAutoloadPaths(
        string $basePath,
        array $autoload
    ): array
    {
        $result = [];

        foreach ($autoload as $paths) {
            if (!is_array($paths)) {
                $paths = [$paths]; // @phpstan-ignore shipmonk.variableTypeOverwritten
            }

            foreach ($paths as $path) {
                $isAbsolute = preg_match('#([a-z]:)?[/\\\\]#Ai', $path);

                if ($isAbsolute === 1) {
                    $absolutePath = $path;
                } else {
                    $absolutePath = $basePath . '/' . $path;
                }

                if (strpos($path, '*') !== false) { // https://getcomposer.org/doc/04-schema.md#classmap
                    $globPaths = glob($absolutePath);

                    if ($globPaths === false) {
                        continue;
                    }

                    foreach ($globPaths as $globPath) {
                        $result[] = $this->realpath($globPath);
                    }

                    continue;
                }

                $result[] = $this->realpath($absolutePath);
            }
        }

        return $result;
    }

    private function realpath(string $path): string
    {
        if (strpos($path, 'phar://') === 0) {
            return $path;
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new LogicException("Unable to realpath '$path'");
        }

        return $realPath;
    }

}

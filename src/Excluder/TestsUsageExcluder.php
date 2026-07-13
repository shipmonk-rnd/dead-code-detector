<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ShipMonk\PHPStan\DeadCode\Composer\ComposerIntrospector;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use function dirname;
use function glob;
use function is_array;
use function is_string;
use function preg_match;
use function realpath;
use function str_contains;
use function str_starts_with;

final class TestsUsageExcluder implements MemberUsageExcluder
{

    private readonly ReflectionProvider $reflectionProvider;

    private readonly ComposerIntrospector $composerIntrospector;

    private readonly bool $enabled;

    /**
     * @var list<string>
     */
    private readonly array $devPaths;

    /**
     * @param list<string>|null $devPaths
     */
    public function __construct(
        ReflectionProvider $reflectionProvider,
        ComposerIntrospector $composerIntrospector,
        bool $enabled,
        ?array $devPaths,
    )
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->composerIntrospector = $composerIntrospector;
        $this->enabled = $enabled;

        if ($devPaths !== null) {
            $resolvedPaths = [];

            foreach ($devPaths as $devPath) {
                $resolvedPaths[] = $this->realpath($devPath);
            }

            $this->devPaths = $resolvedPaths;
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
        Scope $scope,
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
            if (str_starts_with($filePath, $devPath)) {
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
        $composerJsonPath = $this->composerIntrospector->autodetectComposerJsonPath();

        if ($composerJsonPath === null) {
            return [];
        }

        $composerJsonData = $this->composerIntrospector->parseComposerJson($composerJsonPath);
        $autoloadDev = $composerJsonData['autoload-dev'] ?? [];

        if (!is_array($autoloadDev)) {
            return [];
        }

        $basePath = dirname($composerJsonPath);

        return [
            ...$this->extractAutoloadPaths($basePath, $autoloadDev['psr-0'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $autoloadDev['psr-4'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $autoloadDev['files'] ?? []),
            ...$this->extractAutoloadPaths($basePath, $autoloadDev['classmap'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractAutoloadPaths(
        string $basePath,
        mixed $autoload,
    ): array
    {
        if (!is_array($autoload)) {
            return [];
        }

        $result = [];

        foreach ($autoload as $paths) {
            if (!is_array($paths)) {
                $paths = [$paths];
            }

            foreach ($paths as $path) {
                if (!is_string($path)) {
                    continue;
                }

                $isAbsolute = preg_match('#([a-z]:)?[/\\\\]#Ai', $path);

                if ($isAbsolute === 1) {
                    $absolutePath = $path;
                } else {
                    $absolutePath = $basePath . '/' . $path;
                }

                if (str_contains($path, '*')) { // https://getcomposer.org/doc/04-schema.md#classmap
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
        if (str_starts_with($path, 'phar://')) {
            return $path;
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new LogicException("Unable to realpath '$path'");
        }

        return $realPath;
    }

}

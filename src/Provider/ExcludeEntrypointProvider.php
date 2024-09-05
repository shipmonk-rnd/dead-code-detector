<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use ReflectionMethod;
use function count;
use function preg_match;

/**
 * Excludes paths based on patterns.
 *
 * <code>
 *  parameters:
 *      shipmonkDeadCode:
 *          entrypoints:
 *              exclude:
 *                  enabled: true
 *                  paths:
 *                      # supports fqcn
 *                      - 'App\Foo\Bar'
 *                      # supports fqcn with regex
 *                      - '/^App\\\Foo\\\.+$/i'
 * </code>
 */
class ExcludeEntrypointProvider implements EntrypointProvider
{

    /**
     * @var list<string>
     */
    private array $paths;

    private bool $enabled;

    /**
     * @param list<string> $paths
     */
    public function __construct(array $paths, bool $enabled)
    {
        $this->paths = $paths;
        $this->enabled = $enabled;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        if (!$this->enabled || count($this->paths) === 0) {
            return false;
        }

        $className = $method->getDeclaringClass()->getName();

        foreach ($this->paths as $path) {
            // check if path is a regex
            // prevents regex error like "preg_match(): Delimiter must not be alphanumeric, backslash, or NUL"
            if (preg_match('/^[A-Za-z\d\\\].+/', $path) === 1) {
                // path doesn't seem to be a regexp
                if ($path === $className) {
                    return true;
                }

                continue;
            }

            if (preg_match($path, $className) === 1) {
                return true;
            }
        }

        return false;
    }

}

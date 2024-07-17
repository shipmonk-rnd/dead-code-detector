<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use ReflectionException;
use ReflectionMethod;
use function array_keys;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

class VendorEntrypointProvider implements EntrypointProvider
{

    /**
     * @var list<string>
     */
    private array $vendorDirs;

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->vendorDirs = array_keys(ClassLoader::getRegisteredLoaders());
        $this->enabled = $enabled;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $methodPrototype = $method->getPrototype();
        } catch (ReflectionException $e) {
            return false; // hasPrototype available since PHP 8.2
        }

        return $this->isForeignMethod($methodPrototype);
    }

    private function isForeignMethod(ReflectionMethod $methodPrototype): bool
    {
        $filePath = $methodPrototype->getDeclaringClass()->getFileName();

        if ($filePath === false) {
            return true; // php core or extension
        }

        $pharPrefix = 'phar://';

        if (strpos($filePath, $pharPrefix) === 0) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach ($this->vendorDirs as $vendorDir) {
            if (str_starts_with($filePath, $vendorDir)) {
                return true;
            }
        }

        return false;
    }

}

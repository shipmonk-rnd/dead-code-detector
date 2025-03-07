<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionMethod;
use function array_keys;
use function strlen;
use function strpos;
use function substr;

class VendorUsageProvider extends ReflectionBasedMemberUsageProvider
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

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $reflectionClass = $method->getDeclaringClass();
        $methodName = $method->getName();

        do {
            if ($this->isForeignMethod($reflectionClass, $methodName)) {
                return true;
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isForeignMethod($interface, $methodName)) {
                    return true;
                }
            }

            foreach ($reflectionClass->getTraits() as $trait) {
                if ($this->isForeignMethod($trait, $methodName)) {
                    return true;
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     */
    private function isForeignMethod(ReflectionClass $reflectionClass, string $methodName): bool
    {
        if (!$reflectionClass->hasMethod($methodName)) {
            return false;
        }

        $filePath = $reflectionClass->getFileName();

        if ($filePath === false) {
            return true; // php core or extension
        }

        $pharPrefix = 'phar://';

        if (strpos($filePath, $pharPrefix) === 0) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach ($this->vendorDirs as $vendorDir) {
            if (strpos($filePath, $vendorDir) === 0) {
                return true;
            }
        }

        return false;
    }

}

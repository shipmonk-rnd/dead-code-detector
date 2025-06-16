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

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        $reflectionClass = $method->getDeclaringClass();
        $methodName = $method->getName();

        $usage = VirtualUsageData::withNote('Method overrides vendor one, thus is expected to be used by vendor code');

        do {
            if ($this->isForeignMethod($reflectionClass, $methodName)) {
                return $usage;
            }

            foreach ($reflectionClass->getInterfaces() as $interface) {
                if ($this->isForeignMethod($interface, $methodName)) {
                    return $usage;
                }
            }

            foreach ($reflectionClass->getTraits() as $trait) {
                if ($this->isForeignMethod($trait, $methodName)) {
                    return $usage;
                }
            }

            $reflectionClass = $reflectionClass->getParentClass();
        } while ($reflectionClass !== false);

        return null;
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     */
    private function isForeignMethod(
        ReflectionClass $reflectionClass,
        string $methodName
    ): bool
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

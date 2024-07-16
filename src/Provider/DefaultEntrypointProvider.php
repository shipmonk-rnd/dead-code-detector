<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

class DefaultEntrypointProvider implements EntrypointProvider
{

    private ReflectionProvider $reflectionProvider;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        $methodName = $method->getName();
        $className = $method->getDeclaringClass()->getName();

        $reflection = $this->reflectionProvider->getClass($className);

        foreach ($reflection->getAncestors() as $ancestor) {
            if ($ancestor === $reflection) {
                continue;
            }

            if (!$ancestor->hasMethod($methodName)) {
                continue;
            }

            if ($this->isForeignMethod($ancestor)) {
                return true;
            }
        }

        return false;
    }

    private function isForeignMethod(ClassReflection $classReflection): bool
    {
        $filePath = $classReflection->getFileName();

        if ($filePath === null) {
            return true; // php core or extension
        }

        $pharPrefix = 'phar://';

        if (strpos($filePath, $pharPrefix) === 0) {
            /** @var string $filePath Cannot resolve to false */
            $filePath = substr($filePath, strlen($pharPrefix));
        }

        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $_) {
            if (str_starts_with($filePath, $vendorDir)) {
                return true;
            }
        }

        return false;
    }

}

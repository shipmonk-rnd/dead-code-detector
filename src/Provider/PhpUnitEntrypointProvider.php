<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use function str_starts_with;

class PhpUnitEntrypointProvider implements EntrypointProvider
{

    /**
     * @var array<string, array<string, bool>>
     */
    private array $dataProviders = [];

    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function isEntrypoint(ReflectionMethod $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->isTestCaseMethod($method)
            || $this->isDataProviderMethod($method);
    }

    private function isTestCaseMethod(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->isSubclassOf(TestCase::class)
            && str_starts_with($method->getName(), 'test');
    }

    private function isDataProviderMethod(ReflectionMethod $originalMethod): bool
    {
        $declaringClass = $originalMethod->getDeclaringClass();
        $declaringClassName = $declaringClass->getName();

        if (!isset($this->dataProviders[$declaringClassName])) {
            foreach ($declaringClass->getMethods() as $method) {
                foreach ($method->getAttributes(DataProvider::class) as $providerAttributeReflection) {
                    /** @var DataProvider $providerAttribute */
                    $providerAttribute = $providerAttributeReflection->newInstance();

                    $this->dataProviders[$declaringClassName][$providerAttribute->methodName()] = true;
                }
            }
        }

        return $this->dataProviders[$declaringClassName][$originalMethod->getName()] ?? false;
    }

}

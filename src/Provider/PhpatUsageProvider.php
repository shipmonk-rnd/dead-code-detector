<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PHPStan\DependencyInjection\Container;
use ReflectionMethod;
use function is_object;
use function str_starts_with;

/**
 * phpat registers architecture tests as services tagged "phpat.test" in the PHPStan DIC.
 * At runtime, phpat iterates those services and invokes their public methods that either
 * carry the #[TestRule] attribute or whose name starts with "test" (see PHPat\Test\TestParser).
 *
 * Their constructors are already covered by PhpStanUsageProvider (registered DIC services),
 * so this provider only marks the invoked test methods as used.
 */
final class PhpatUsageProvider extends ReflectionBasedMemberUsageProvider
{

    private const TEST_TAG = 'phpat.test';

    private const TEST_RULE_ATTRIBUTE = 'PHPat\Test\Attributes\TestRule';

    private readonly bool $enabled;

    private readonly Container $container;

    /**
     * @var array<string, true>|null Set of class names tagged as phpat tests, lazily resolved.
     */
    private ?array $testClasses = null;

    public function __construct(
        ?bool $enabled,
        Container $container,
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('phpat/phpat');
        $this->container = $container;
    }

    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->enabled) {
            return null;
        }

        if (!$method->isPublic()) {
            return null;
        }

        if (!isset($this->getTestClasses()[$method->getDeclaringClass()->getName()])) {
            return null;
        }

        if (!$this->isTestMethod($method)) {
            return null;
        }

        return VirtualUsageData::withNote('Architecture test method invoked by phpat');
    }

    private function isTestMethod(ReflectionMethod $method): bool
    {
        if ($method->getAttributes(self::TEST_RULE_ATTRIBUTE) !== []) {
            return true;
        }

        // phpat invokes every public method whose name starts with "test"
        return str_starts_with($method->getName(), 'test');
    }

    /**
     * @return array<string, true>
     */
    private function getTestClasses(): array
    {
        if ($this->testClasses === null) {
            $this->testClasses = [];

            foreach ($this->container->getServicesByTag(self::TEST_TAG) as $service) {
                if (is_object($service)) {
                    $this->testClasses[$service::class] = true;
                }
            }
        }

        return $this->testClasses;
    }

}

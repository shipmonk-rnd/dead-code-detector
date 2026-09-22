<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Naming\CaseInsensitiveName;
use function in_array;
use function strlen;
use function strrpos;
use function substr;

/**
 * Laravel calls a setUp{Trait} and tearDown{Trait} hook for every trait a test case uses,
 * and the database traits read their settings off the test case with property_exists().
 *
 * @see \Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle::setUpTraits()
 */
final class LaravelTestLifecycleUsageProvider extends ReflectionBasedMemberUsageProvider implements ActivatableUsageProvider
{

    private const TEST_CASE_CLASS = 'Illuminate\Foundation\Testing\TestCase';

    /**
     * Vendor-declared ones, such as mockConsoleOutput or defaultHeaders, are left to VendorUsageProvider.
     *
     * @see \Illuminate\Foundation\Testing\RefreshDatabase::connectionsToTransact()
     * @see \Illuminate\Foundation\Testing\DatabaseTruncation
     * @see \Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands
     */
    private const REFLECTED_PROPERTIES = [
        'connectionsToTransact',
        'connectionsToTruncate',
        'tablesToTruncate',
        'exceptTables',
        'seed',
        'seeder',
        'dropViews',
        'dropTypes',
    ];

    private readonly bool $enabled;

    public function __construct(
        ?bool $enabled,
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('laravel/framework');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        $declaringClass = $method->getDeclaringClass();

        if (!$declaringClass->isSubclassOf(self::TEST_CASE_CLASS)) {
            return null;
        }

        $traitName = $this->getHookedTraitName($method->getName());

        if ($traitName === null) {
            return null;
        }

        foreach ($this->getTraitNames($declaringClass) as $usedTraitName) {
            if (CaseInsensitiveName::equals($traitName, $this->getClassBasename($usedTraitName))) {
                return VirtualUsageData::withNote('Laravel test lifecycle hook, called for each used trait by setUpTraits()');
            }
        }

        return null;
    }

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        if (!in_array($property->getName(), self::REFLECTED_PROPERTIES, true)) {
            return null; // property names are case-sensitive, unlike the method names above
        }

        if (!$property->getDeclaringClass()->isSubclassOf(self::TEST_CASE_CLASS)) {
            return null;
        }

        return VirtualUsageData::withNote('Laravel test database setting, read through property_exists()');
    }

    private function getHookedTraitName(string $methodName): ?string
    {
        foreach (['setUp', 'tearDown'] as $prefix) {
            if (!CaseInsensitiveName::startsWith($methodName, $prefix)) {
                continue;
            }

            $traitName = substr($methodName, strlen($prefix));

            if ($traitName !== '') { // skip bare setUp and tearDown, they carry no trait name
                return $traitName;
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<string>
     */
    private function getTraitNames(ReflectionClass $class): array
    {
        $traitNames = [];
        $currentClass = $class;

        do {
            foreach ($currentClass->getTraits() as $trait) {
                $traitNames[] = $trait->getName();

                foreach ($this->getTraitNames($trait) as $nestedTraitName) {
                    $traitNames[] = $nestedTraitName;
                }
            }

            $currentClass = $currentClass->getParentClass(); // Laravel resolves the list with class_uses_recursive(), which walks parents too
        } while ($currentClass !== false);

        return $traitNames;
    }

    private function getClassBasename(string $className): string
    {
        $lastSeparator = strrpos($className, '\\');

        return $lastSeparator !== false ? substr($className, $lastSeparator + 1) : $className;
    }

}

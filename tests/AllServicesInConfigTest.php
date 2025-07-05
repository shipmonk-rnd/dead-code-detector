<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode;

use DirectoryIterator;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\EnumCaseRef;
use ShipMonk\PHPStan\DeadCode\Graph\EnumCaseUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveClassMemberVisitor;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function array_merge;
use function class_exists;
use function count;
use function in_array;
use function interface_exists;
use function str_replace;
use function trait_exists;

class AllServicesInConfigTest extends PHPStanTestCase
{

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return array_merge(
            parent::getAdditionalConfigFiles(),
            [__DIR__ . '/../rules.neon'],
        );
    }

    public function test(): void
    {
        $this->expectNotToPerformAssertions();

        $directory = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $excluded = [
            VirtualUsageData::class,
            UsageOrigin::class,
            EnumCaseUsage::class,
            ClassMethodUsage::class,
            ClassMethodRef::class,
            ClassConstantRef::class,
            EnumCaseRef::class,
            ClassConstantUsage::class,
            CollectedUsage::class,
            BlackMember::class,
            RemoveDeadCodeTransformer::class,
            RemoveClassMemberVisitor::class,
        ];

        /** @var DirectoryIterator $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->mapPathToClassName($file->getPathname());
            $reflectionClass = new ReflectionClass($className);

            if ($reflectionClass->isInterface() || $reflectionClass->isTrait() || $reflectionClass->isAbstract()) {
                continue;
            }

            if ($this->hasAllMethodsStatic($reflectionClass)) {
                continue;
            }

            if (in_array($className, $excluded, true)) {
                continue;
            }

            if (self::getContainer()->findServiceNamesByType($className) === []) {
                self::fail('Class ' . $className . ' not registered in rules.neon');
            }
        }
    }

    /**
     * @return class-string
     */
    private function mapPathToClassName(string $pathname): string
    {
        $namespace = 'ShipMonk\\PHPStan\\DeadCode\\';
        $relativePath = str_replace(__DIR__ . '/../src/', '', $pathname);
        $classString = $namespace . str_replace('/', '\\', str_replace([__DIR__ . '/../src', '.php'], '', $relativePath));

        if (!class_exists($classString) && !interface_exists($classString) && !trait_exists($classString)) {
            throw new LogicException('Class ' . $classString . ' does not exist');
        }

        return $classString;
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     */
    private function hasAllMethodsStatic(ReflectionClass $reflectionClass): bool
    {
        $methods = $reflectionClass->getMethods();

        if (count($methods) === 0) {
            return false;
        }

        foreach ($methods as $method) {
            if (!$method->isStatic()) {
                return false;
            }
        }

        return true;
    }

}

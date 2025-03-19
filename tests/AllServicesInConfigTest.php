<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode;

use DirectoryIterator;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ShipMonk\PHPStan\DeadCode\Collector\BufferedUsageCollector;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\NeverReportedReason;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsage;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveClassMemberVisitor;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function array_keys;
use function array_merge;
use function class_exists;
use function implode;
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
        $missingClassNames = [];
        $excluded = [
            VirtualUsage::class,
            UsageOrigin::class,
            ClassMethodUsage::class,
            ClassMethodRef::class,
            ClassConstantRef::class,
            ClassConstantUsage::class,
            ClassMemberRef::class,
            ClassMemberUsage::class,
            ClassLikeKind::class,
            CollectedUsage::class,
            Visibility::class,
            BlackMember::class,
            MemberUsageProvider::class,
            BufferedUsageCollector::class,
            ReflectionBasedMemberUsageProvider::class,
            RemoveDeadCodeTransformer::class,
            RemoveClassMemberVisitor::class,
            MemberType::class,
            NeverReportedReason::class,
        ];

        /** @var DirectoryIterator $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->mapPathToClassName($file->getPathname());

            if (in_array($className, $excluded, true)) {
                continue;
            }

            if (self::getContainer()->findServiceNamesByType($className) !== []) {
                continue;
            }

            $missingClassNames[$className] = true;
        }

        if ($missingClassNames !== []) {
            self::fail('Class ' . implode(', ', array_keys($missingClassNames)) . ' not registered in rules.neon');
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

}

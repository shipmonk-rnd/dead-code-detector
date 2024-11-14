<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode;

use DirectoryIterator;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantFetch;
use ShipMonk\PHPStan\DeadCode\Crate\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodCall;
use ShipMonk\PHPStan\DeadCode\Crate\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
use ShipMonk\PHPStan\DeadCode\Crate\Visibility;
use ShipMonk\PHPStan\DeadCode\Provider\MethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SimpleMethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveClassMemberVisitor;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveDeadCodeTransformer;
use function array_keys;
use function array_merge;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function str_replace;

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
            ClassMethodCall::class,
            ClassMethodRef::class,
            ClassConstantRef::class,
            ClassConstantFetch::class,
            ClassMemberRef::class,
            ClassMemberUsage::class,
            Kind::class,
            Visibility::class,
            MethodEntrypointProvider::class,
            SimpleMethodEntrypointProvider::class,
            RemoveDeadCodeTransformer::class,
            RemoveClassMemberVisitor::class,
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

        if (!class_exists($classString) && !interface_exists($classString)) {
            throw new LogicException('Class ' . $classString . ' does not exist');
        }

        return $classString;
    }

}

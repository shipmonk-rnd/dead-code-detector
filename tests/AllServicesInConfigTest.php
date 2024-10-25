<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode;

use DirectoryIterator;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ShipMonk\PHPStan\DeadCode\Crate\Call;
use ShipMonk\PHPStan\DeadCode\Crate\Kind;
use ShipMonk\PHPStan\DeadCode\Crate\Method;
use ShipMonk\PHPStan\DeadCode\Crate\Visibility;
use ShipMonk\PHPStan\DeadCode\Provider\MethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SimpleMethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveMethodCodeTransformer;
use ShipMonk\PHPStan\DeadCode\Transformer\RemoveMethodVisitor;
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
            Call::class,
            Method::class,
            Kind::class,
            Visibility::class,
            MethodEntrypointProvider::class,
            SimpleMethodEntrypointProvider::class,
            RemoveMethodCodeTransformer::class,
            RemoveMethodVisitor::class,
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

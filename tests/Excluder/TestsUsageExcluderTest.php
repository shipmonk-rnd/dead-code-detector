<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use ShipMonk\PHPStan\DeadCode\Composer\ComposerIntrospector;
use function realpath;

final class TestsUsageExcluderTest extends PHPStanTestCase
{

    public function testAutodetectComposerDevPaths(): void
    {
        $excluder = new TestsUsageExcluder(self::getContainer()->getByType(ReflectionProvider::class), new ComposerIntrospector(), true, null);

        $excluderReflection = new ReflectionClass(TestsUsageExcluder::class);
        $devPathsPropertyReflection = $excluderReflection->getProperty('devPaths');

        self::assertSame([
            realpath(__DIR__ . '/../../tests'),
            realpath(__DIR__ . '/../../tests/Rule/data'),
        ], $devPathsPropertyReflection->getValue($excluder));
    }

    public function testDevPathMatchesOnDirectoryBoundary(): void
    {
        $excluder = new TestsUsageExcluder(
            self::getContainer()->getByType(ReflectionProvider::class),
            new ComposerIntrospector(),
            true,
            [__DIR__ . '/data/boundary/tests'],
        );

        $isWithinDevPaths = (new ReflectionClass(TestsUsageExcluder::class))->getMethod('isWithinDevPaths');

        self::assertTrue($isWithinDevPaths->invoke($excluder, realpath(__DIR__ . '/data/boundary/tests/Foo.php')));
        self::assertTrue($isWithinDevPaths->invoke($excluder, realpath(__DIR__ . '/data/boundary/tests')));
        self::assertFalse($isWithinDevPaths->invoke($excluder, realpath(__DIR__ . '/data/boundary/tests-helpers/Bar.php')));
    }

}

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

    public function testMissingAutoloadDevPathIsSkipped(): void
    {
        $excluder = new TestsUsageExcluder(self::getContainer()->getByType(ReflectionProvider::class), new ComposerIntrospector(), true, null);

        $extractAutoloadPaths = (new ReflectionClass(TestsUsageExcluder::class))->getMethod('extractAutoloadPaths');

        self::assertSame([], $extractAutoloadPaths->invoke($excluder, __DIR__, ['App\\Tests\\' => 'this-dir-does-not-exist']));
        self::assertSame(
            [realpath(__DIR__)],
            $extractAutoloadPaths->invoke($excluder, __DIR__, ['App\\Tests\\' => ['this-dir-does-not-exist', '.']]),
        );
    }

    public function testNoAutodetectionWhenDisabled(): void
    {
        $excluder = new TestsUsageExcluder(self::getContainer()->getByType(ReflectionProvider::class), new ComposerIntrospector(), false, null);

        $devPathsPropertyReflection = (new ReflectionClass(TestsUsageExcluder::class))->getProperty('devPaths');

        self::assertSame([], $devPathsPropertyReflection->getValue($excluder));
    }

}

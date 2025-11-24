<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Excluder;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use function realpath;
use const PHP_VERSION_ID;

class TestsUsageExcluderTest extends PHPStanTestCase
{

    public function testAutodetectComposerDevPaths(): void
    {
        $excluder = new TestsUsageExcluder(self::getContainer()->getByType(ReflectionProvider::class), true, null);

        $excluderReflection = new ReflectionClass(TestsUsageExcluder::class);
        $devPathsPropertyReflection = $excluderReflection->getProperty('devPaths');
        if (PHP_VERSION_ID < 8_01_00) {
            $devPathsPropertyReflection->setAccessible(true);
        }

        self::assertSame([
            realpath(__DIR__ . '/../../tests'),
            realpath(__DIR__ . '/../../tests/Rule/data'),
        ], $devPathsPropertyReflection->getValue($excluder));
    }

}

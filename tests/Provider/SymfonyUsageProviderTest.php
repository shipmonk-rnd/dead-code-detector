<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use function mkdir;
use function realpath;
use function rmdir;
use const PHP_VERSION_ID;

final class SymfonyUsageProviderTest extends PHPStanTestCase
{

    public function testAutodetectConfigDir(): void
    {
        $configDir = __DIR__ . '/../../config';
        @mkdir($configDir);

        $provider = new SymfonyUsageProvider(self::getContainer(), true, null);

        $providerReflection = new ReflectionClass(SymfonyUsageProvider::class);
        $configDirPropertyReflection = $providerReflection->getProperty('configDir');
        if (PHP_VERSION_ID < 8_01_00) {
            $configDirPropertyReflection->setAccessible(true);
        }

        $configDirFromProperty = $configDirPropertyReflection->getValue($provider);

        self::assertIsString($configDirFromProperty);
        self::assertNotFalse(realpath($configDir));
        self::assertNotFalse(realpath($configDirFromProperty));
        self::assertSame(
            realpath($configDir),
            realpath($configDirFromProperty),
        );

        @rmdir($configDir);
    }

}

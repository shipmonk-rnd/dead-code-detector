<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use function mkdir;
use function realpath;
use function rmdir;

final class SymfonyUsageProviderTest extends PHPStanTestCase
{

    public function testAutodetectConfigDir(): void
    {
        $configDir = __DIR__ . '/../../config';
        @mkdir($configDir);

        $provider = new SymfonyUsageProvider(self::getContainer(), true, null, []);

        $providerReflection = new ReflectionClass(SymfonyUsageProvider::class);
        $configDirPropertyReflection = $providerReflection->getProperty('configDir');

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

    public function testExplicitContainerXmlPaths(): void
    {
        $containerXmlPath = __DIR__ . '/../Rule/data/providers/symfony/services.xml';

        $provider = new SymfonyUsageProvider(self::getContainer(), true, null, [$containerXmlPath]);

        $providerReflection = new ReflectionClass(SymfonyUsageProvider::class);
        $dicCallsReflection = $providerReflection->getProperty('dicCalls');

        /** @var array<string, array<string, true>> $dicCalls */
        $dicCalls = $dicCallsReflection->getValue($provider);

        self::assertArrayHasKey('Symfony\DicClass1', $dicCalls);
        self::assertArrayHasKey('__construct', $dicCalls['Symfony\DicClass1']);
        self::assertArrayHasKey('calledViaDic', $dicCalls['Symfony\DicClass1']);
    }

    public function testExplicitContainerXmlPathsTakesPrecedenceOverContainer(): void
    {
        $containerXmlPath = __DIR__ . '/../Rule/data/providers/symfony/services.xml';

        // Even though self::getContainer() has no symfony config, the explicit paths are used
        $provider = new SymfonyUsageProvider(self::getContainer(), true, null, [$containerXmlPath]);

        $providerReflection = new ReflectionClass(SymfonyUsageProvider::class);
        $dicCallsReflection = $providerReflection->getProperty('dicCalls');

        /** @var array<string, array<string, true>> $dicCalls */
        $dicCalls = $dicCallsReflection->getValue($provider);

        self::assertNotEmpty($dicCalls);
    }

    public function testEmptyContainerXmlPathsFallsBackToContainer(): void
    {
        // When containerXmlPaths is empty, it falls back to getContainerXmlPath(container)
        // self::getContainer() has no symfony parameter, so no DIC classes are loaded
        $provider = new SymfonyUsageProvider(self::getContainer(), true, null, []);

        $providerReflection = new ReflectionClass(SymfonyUsageProvider::class);
        $dicCallsReflection = $providerReflection->getProperty('dicCalls');

        /** @var array<string, array<string, true>> $dicCalls */
        $dicCalls = $dicCallsReflection->getValue($provider);

        self::assertEmpty($dicCalls);
    }

}

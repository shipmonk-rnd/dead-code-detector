<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;

final class ComposerUsageProviderTest extends PHPStanTestCase
{

    public function testComposerJsonPathCollectsScriptCallbacks(): void
    {
        $composerJsonPath = __DIR__ . '/../Rule/data/providers/composer/composer.json';

        $provider = new ComposerUsageProvider(true, $composerJsonPath);
        $scriptCalls = $this->getScriptCalls($provider);

        self::assertArrayHasKey('ComposerProvider\Scripts', $scriptCalls);
        self::assertArrayHasKey('postInstall', $scriptCalls['ComposerProvider\Scripts']);
        self::assertArrayHasKey('first', $scriptCalls['ComposerProvider\Scripts']);

        // leading backslash is trimmed
        self::assertArrayHasKey('withLeadingSlash', $scriptCalls['ComposerProvider\Scripts']);

        // listeners with spaces are shell commands, not PHP callbacks
        self::assertArrayNotHasKey('notAPhpScript', $scriptCalls['ComposerProvider\Scripts']);

        self::assertArrayHasKey('ComposerProvider\Child', $scriptCalls);
    }

    public function testAutodetectsComposerJson(): void
    {
        $provider = new ComposerUsageProvider(true, null);

        // this repository has no PHP callbacks in its own composer.json scripts
        self::assertSame([], $this->getScriptCalls($provider));
    }

    public function testAutodetectionRequiresSingleVendorDir(): void
    {
        $extraLoader = new ClassLoader(__DIR__);
        $extraLoader->register();

        try {
            $provider = new ComposerUsageProvider(true, null);

            self::assertSame([], $this->getScriptCalls($provider));
        } finally {
            $extraLoader->unregister();
        }
    }

    public function testDisabled(): void
    {
        $provider = new ComposerUsageProvider(false, __DIR__ . '/not-a-file.json');

        self::assertSame([], $this->getScriptCalls($provider));
    }

    public function testInvalidPathThrows(): void
    {
        self::expectException(LogicException::class);

        new ComposerUsageProvider(true, __DIR__ . '/not-a-file.json');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getScriptCalls(ComposerUsageProvider $provider): array
    {
        $providerReflection = new ReflectionClass(ComposerUsageProvider::class);
        $scriptCallsReflection = $providerReflection->getProperty('scriptCalls');

        /** @var array<string, array<string, string>> $scriptCalls */
        $scriptCalls = $scriptCallsReflection->getValue($provider);

        return $scriptCalls;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PhpatTestFixture\NotRegisteredArchitectureTest;
use PhpatTestFixture\RegisteredArchitectureTest;
use PHPStan\DependencyInjection\Container;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionMethod;

final class PhpatUsageProviderTest extends PHPStanTestCase
{

    public function testTestMethodsOfRegisteredTestsAreMarkedUsed(): void
    {
        $provider = $this->createProvider(enabled: true, registeredTests: [new RegisteredArchitectureTest()]);

        // invoked by phpat: test-prefixed name
        self::assertInstanceOf(VirtualUsageData::class, $this->shouldMarkMethodAsUsed($provider, RegisteredArchitectureTest::class, 'testSrcDoesNotDependOnTests'));

        // invoked by phpat: #[TestRule] attribute regardless of name
        self::assertInstanceOf(VirtualUsageData::class, $this->shouldMarkMethodAsUsed($provider, RegisteredArchitectureTest::class, 'customNamedRule'));
    }

    public function testNonTestMethodsAreNotMarkedUsed(): void
    {
        $provider = $this->createProvider(enabled: true, registeredTests: [new RegisteredArchitectureTest()]);

        // public, but neither test-prefixed nor #[TestRule]
        self::assertNull($this->shouldMarkMethodAsUsed($provider, RegisteredArchitectureTest::class, 'helperNotInvokedByPhpat'));

        // phpat only invokes public methods
        self::assertNull($this->shouldMarkMethodAsUsed($provider, RegisteredArchitectureTest::class, 'testPrivate'));
    }

    public function testMethodsOfUnregisteredClassesAreNotMarkedUsed(): void
    {
        // only RegisteredArchitectureTest is tagged phpat.test
        $provider = $this->createProvider(enabled: true, registeredTests: [new RegisteredArchitectureTest()]);

        self::assertNull($this->shouldMarkMethodAsUsed($provider, NotRegisteredArchitectureTest::class, 'testSomething'));
    }

    public function testDisabledProviderMarksNothing(): void
    {
        $provider = $this->createProvider(enabled: false, registeredTests: [new RegisteredArchitectureTest()]);

        self::assertNull($this->shouldMarkMethodAsUsed($provider, RegisteredArchitectureTest::class, 'testSrcDoesNotDependOnTests'));
    }

    private function shouldMarkMethodAsUsed(
        PhpatUsageProvider $provider,
        string $class,
        string $method,
    ): mixed
    {
        $providerMethod = new ReflectionMethod($provider, 'shouldMarkMethodAsUsed');

        return $providerMethod->invoke($provider, new ReflectionMethod($class, $method));
    }

    /**
     * @param list<object> $registeredTests
     */
    private function createProvider(
        bool $enabled,
        array $registeredTests,
    ): PhpatUsageProvider
    {
        $container = $this->createMock(Container::class);
        $container->method('getServicesByTag')
            ->willReturnCallback(
                static fn (string $tag): array => $tag === 'phpat.test' ? $registeredTests : [],
            );

        return new PhpatUsageProvider($enabled, $container);
    }

}

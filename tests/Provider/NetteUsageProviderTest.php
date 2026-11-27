<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

final class NetteUsageProviderTest extends PHPStanTestCase
{

    public function testContainerPathsCollectsServiceClasses(): void
    {
        $neonPath = __DIR__ . '/../Rule/data/providers/nette/services.neon';

        $provider = new NetteUsageProvider(
            self::getContainer()->getByType(ReflectionProvider::class),
            true,
            [$neonPath],
        );

        $providerReflection = new ReflectionClass(NetteUsageProvider::class);
        $serviceClassesReflection = $providerReflection->getProperty('serviceClasses');

        /** @var array<string, true> $serviceClasses */
        $serviceClasses = $serviceClassesReflection->getValue($provider);

        // every supported service-definition shape resolves to the instantiated class
        self::assertArrayHasKey('NetteContainerProvider\RegisteredScalar', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredNamed', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredEntity', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredViaCreate', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredViaFactoryKey', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredViaClass', $serviceClasses);
        self::assertArrayHasKey('NetteContainerProvider\RegisteredViaType', $serviceClasses);

        // an inherited constructor is credited on the declaring ancestor, not the registered child
        self::assertArrayHasKey('NetteContainerProvider\ServiceParent', $serviceClasses);
        self::assertArrayNotHasKey('NetteContainerProvider\RegisteredChild', $serviceClasses);

        // a factory interface marks the constructor of its create() return type
        self::assertArrayHasKey('NetteContainerProvider\FactoryProduct', $serviceClasses);
        self::assertArrayNotHasKey('NetteContainerProvider\ProductFactory', $serviceClasses);

        // imported services and factory-call references are not instantiated by the container
        self::assertArrayNotHasKey('NetteContainerProvider\ImportedService', $serviceClasses);
    }

    /**
     * @param list<string> $expectedClasses
     */
    #[DataProvider('provideNeonShapes')]
    public function testFindServiceClassesInNeon(
        string $neon,
        array $expectedClasses,
    ): void
    {
        self::assertSame($expectedClasses, NetteUsageProvider::findServiceClassesInNeon($neon));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideNeonShapes(): iterable
    {
        yield 'scalar FQCN' => ["services:\n\t- Foo\\Bar", ['Foo\Bar']];
        yield 'leading backslash is trimmed' => ["services:\n\t- \\Foo\\Bar", ['Foo\Bar']];
        yield 'named entry' => ["services:\n\tname: Foo\\Bar", ['Foo\Bar']];
        yield 'entity with arguments' => ["services:\n\tname: Foo\\Bar(arg)", ['Foo\Bar']];
        yield 'create key' => ["services:\n\tname:\n\t\tcreate: Foo\\Bar", ['Foo\Bar']];
        yield 'create key with constructor arguments' => ["services:\n\tname:\n\t\tcreate: Foo\\Bar(@dep, 1)", ['Foo\Bar']];
        yield 'factory key' => ["services:\n\tname:\n\t\tfactory: Foo\\Bar", ['Foo\Bar']];
        yield 'class key' => ["services:\n\tname:\n\t\tclass: Foo\\Bar", ['Foo\Bar']];
        yield 'type key' => ["services:\n\tname:\n\t\ttype: Foo\\Bar", ['Foo\Bar']];
        yield 'implement key' => ["services:\n\tname:\n\t\timplement: Foo\\BarFactory", ['Foo\BarFactory']];
        yield 'create wins over type' => ["services:\n\tname:\n\t\tcreate: Foo\\Bar\n\t\ttype: Foo\\Baz", ['Foo\Bar']];

        yield 'imported: true is skipped' => ["services:\n\tname:\n\t\ttype: Foo\\Bar\n\t\timported: true", []];
        yield 'imported: yes is skipped' => ["services:\n\tname:\n\t\ttype: Foo\\Bar\n\t\timported: yes", []];
        yield 'static factory method call is skipped' => ["services:\n\tname:\n\t\tcreate: Foo\\Bar::create()", []];
        yield 'service factory method call is skipped' => ["services:\n\tname:\n\t\tcreate: @other::make()", []];
        yield 'callable array factory is skipped' => ["services:\n\tname:\n\t\tcreate: [Foo\\Bar, create]", []];
        yield 'service reference is skipped' => ["services:\n\tname: @other", []];
        yield 'no services block' => ['parameters:', []];
        yield 'empty services block' => ['services:', []];
    }

    /**
     * @param array{classes: list<string>, staticMethods: list<array{string, string}>} $expected
     */
    #[DataProvider('provideNeonStatements')]
    public function testFindStatementsInNeon(
        string $neon,
        array $expected,
    ): void
    {
        self::assertSame($expected, NetteUsageProvider::findStatementsInNeon($neon));
    }

    /**
     * @return iterable<string, array{string, array{classes: list<string>, staticMethods: list<array{string, string}>}}>
     */
    public static function provideNeonStatements(): iterable
    {
        yield 'static factory' => ["services:\n\t- Foo\\Bar::create()", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory with leading backslash' => ["services:\n\tname:\n\t\tcreate: \\Foo\\Bar::create(1)", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory without parentheses' => ["services:\n\t- Foo\\Bar::create", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory in create without parentheses' => ["services:\n\tname:\n\t\tcreate: \\Foo\\Bar::create", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory in legacy factory key' => ["services:\n\tname:\n\t\tfactory: Foo\\Bar::create", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory as array callable' => ["services:\n\tname: [Foo\\Bar, create]", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static factory in create as array callable' => ["services:\n\tname:\n\t\tcreate: [Foo\\Bar, create]", ['classes' => [], 'staticMethods' => [['Foo\\Bar', 'create']]]];
        yield 'static setup without parentheses' => ["services:\n\tname:\n\t\ttype: Foo\\Bar\n\t\tsetup:\n\t\t\t- Foo\\Baz::register\n\t\t\t- setFoo\n\t\t\t- [Foo\\Baz, notCallable]", ['classes' => [], 'staticMethods' => [['Foo\\Baz', 'register']]]];
        yield 'static decorator setup without parentheses' => ["decorator:\n\tFoo\\Bar:\n\t\tsetup:\n\t\t\t- Foo\\Baz::register", ['classes' => [], 'staticMethods' => [['Foo\\Baz', 'register']]]];
        yield 'imported service creator is skipped' => ["services:\n\tname:\n\t\tcreate: Foo\\Bar::create\n\t\timported: true", ['classes' => [], 'staticMethods' => []]];
        yield 'callable string outside of services is skipped' => ["someExtension:\n\tcallback: Foo\\Bar::create", ['classes' => [], 'staticMethods' => []]];
        yield 'service with statement argument' => ["services:\n\t- Foo\\Bar(Foo\\Baz())", ['classes' => ['Foo\\Bar', 'Foo\\Baz'], 'staticMethods' => []]];
        yield 'setup with statement argument' => ["services:\n\tname:\n\t\ttype: Foo\\Bar\n\t\tsetup:\n\t\t\t- add(Foo\\Baz())", ['classes' => ['add', 'Foo\\Baz'], 'staticMethods' => []]];
        yield 'extension config' => ["sentry:\n\tclient:\n\t\tintegrations:\n\t\t\t- Foo\\Integration(null)", ['classes' => ['Foo\\Integration'], 'staticMethods' => []]];
        yield 'service reference is skipped' => ["services:\n\tname:\n\t\tcreate: @other::make()", ['classes' => [], 'staticMethods' => []]];
        yield 'imported service is skipped' => ["services:\n\tname:\n\t\ttype: Foo\\Bar\n\t\targuments: [Foo\\Baz()]\n\t\timported: true", ['classes' => [], 'staticMethods' => []]];
        yield 'parameters are skipped' => ["parameters:\n\tfoo: Foo\\Bar()", ['classes' => [], 'staticMethods' => []]];
        yield 'plain strings are skipped' => ["services:\n\t- Foo\\Bar", ['classes' => [], 'staticMethods' => []]];
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\Container;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;
use PHPStan\Symfony\ServiceMapFactory;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Provider\DoctrineEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\NetteEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpStanEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpUnitEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SymfonyEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VendorEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Reflection\ClassHierarchy;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<DeadMethodRule>
 */
class DeadMethodRuleTest extends RuleTestCase
{

    private ClassHierarchy $classHierarchy;

    public function setUp(): void
    {
        $this->classHierarchy = new ClassHierarchy();
    }

    protected function getRule(): DeadMethodRule
    {
        return new DeadMethodRule(
            self::getContainer()->getByType(ReflectionProvider::class),
            $this->classHierarchy,
            $this->getEntrypointProviders(),
        );
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [
            new ClassDefinitionCollector(),
            new MethodDefinitionCollector(),
            new MethodCallCollector(),
        ];
    }

    /**
     * @dataProvider provideFiles
     */
    public function testDead(string $file, ?int $lowestPhpVersion = null): void
    {
        if ($lowestPhpVersion !== null && PHP_VERSION_ID < $lowestPhpVersion) {
            self::markTestSkipped('Requires PHP ' . $lowestPhpVersion);
        }

        $this->analyseFile($file);
    }

    /**
     * @return array<string, array{0: string, 1?: int}>
     */
    public static function provideFiles(): iterable
    {
        yield 'enum' => [__DIR__ . '/data/DeadMethodRule/enum.php', 80_000];
        yield 'code' => [__DIR__ . '/data/DeadMethodRule/basic.php'];
        yield 'ctor' => [__DIR__ . '/data/DeadMethodRule/ctor.php'];
        yield 'ctor-interface' => [__DIR__ . '/data/DeadMethodRule/ctor-interface.php'];
        yield 'entrypoint' => [__DIR__ . '/data/DeadMethodRule/entrypoint.php'];
        yield 'first-class-callable' => [__DIR__ . '/data/DeadMethodRule/first-class-callable.php'];
        yield 'overwriting-1' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-1.php'];
        yield 'overwriting-2' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-2.php'];
        yield 'overwriting-3' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-3.php'];
        yield 'overwriting-4' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-4.php'];
        yield 'overwriting-5' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-5.php'];
        yield 'trait-1' => [__DIR__ . '/data/DeadMethodRule/traits-1.php'];
        yield 'trait-2' => [__DIR__ . '/data/DeadMethodRule/traits-2.php'];
        yield 'trait-3' => [__DIR__ . '/data/DeadMethodRule/traits-3.php'];
        yield 'trait-4' => [__DIR__ . '/data/DeadMethodRule/traits-4.php'];
        yield 'trait-5' => [__DIR__ . '/data/DeadMethodRule/traits-5.php'];
        yield 'trait-6' => [__DIR__ . '/data/DeadMethodRule/traits-6.php'];
        yield 'trait-7' => [__DIR__ . '/data/DeadMethodRule/traits-7.php'];
        yield 'trait-8' => [__DIR__ . '/data/DeadMethodRule/traits-8.php'];
        yield 'trait-9' => [__DIR__ . '/data/DeadMethodRule/traits-9.php'];
        yield 'trait-10' => [__DIR__ . '/data/DeadMethodRule/traits-10.php'];
        yield 'nullsafe' => [__DIR__ . '/data/DeadMethodRule/nullsafe.php'];
        yield 'dead-in-parent-1' => [__DIR__ . '/data/DeadMethodRule/dead-in-parent-1.php'];
        yield 'indirect-interface' => [__DIR__ . '/data/DeadMethodRule/indirect-interface.php'];
        yield 'parent-call-1' => [__DIR__ . '/data/DeadMethodRule/parent-call-1.php'];
        yield 'parent-call-2' => [__DIR__ . '/data/DeadMethodRule/parent-call-2.php'];
        yield 'parent-call-3' => [__DIR__ . '/data/DeadMethodRule/parent-call-3.php'];
        yield 'parent-call-4' => [__DIR__ . '/data/DeadMethodRule/parent-call-4.php'];
        yield 'attribute' => [__DIR__ . '/data/DeadMethodRule/attribute.php'];
        yield 'dynamic-method' => [__DIR__ . '/data/DeadMethodRule/dynamic-method.php'];
        yield 'call-on-class-string' => [__DIR__ . '/data/DeadMethodRule/class-string.php'];
        yield 'array-map-1' => [__DIR__ . '/data/DeadMethodRule/array-map-1.php'];
        yield 'unknown-class' => [__DIR__ . '/data/DeadMethodRule/unknown-class.php'];
        yield 'provider-default' => [__DIR__ . '/data/DeadMethodRule/providers/default.php'];
        yield 'provider-symfony' => [__DIR__ . '/data/DeadMethodRule/providers/symfony.php', 80_000];
        yield 'provider-phpunit' => [__DIR__ . '/data/DeadMethodRule/providers/phpunit.php', 80_000];
        yield 'provider-doctrine' => [__DIR__ . '/data/DeadMethodRule/providers/doctrine.php', 80_000];
        yield 'provider-phpstan' => [__DIR__ . '/data/DeadMethodRule/providers/phpstan.php', 80_000];
        yield 'provider-nette' => [__DIR__ . '/data/DeadMethodRule/providers/nette.php'];
    }

    /**
     * @return list<EntrypointProvider>
     */
    private function getEntrypointProviders(): array
    {
        return [
            new class implements EntrypointProvider
            {

                public function isEntrypoint(ReflectionMethod $method): bool
                {
                    return $method->getDeclaringClass()->getName() === 'DeadEntrypoint\Entrypoint';
                }

            },
            new VendorEntrypointProvider(
                true,
            ),
            new PhpUnitEntrypointProvider(
                true,
                self::getContainer()->getByType(PhpDocParser::class),
                self::getContainer()->getByType(Lexer::class),
            ),
            new SymfonyEntrypointProvider(
                self::getContainer()->getByType(ReflectionProvider::class),
                $this->classHierarchy,
                $this->createServiceMapFactoryMock(),
                true,
            ),
            new DoctrineEntrypointProvider(
                true,
            ),
            new PhpStanEntrypointProvider(
                true,
                $this->createPhpStanContainerMock(),
            ),
            new NetteEntrypointProvider(
                self::getContainer()->getByType(ReflectionProvider::class),
                true,
            ),
        ];
    }

    private function createPhpStanContainerMock(): Container
    {
        $mock = $this->createMock(Container::class);
        $mock->method('findServiceNamesByType')
            ->willReturnCallback(
                static function (string $type): array {
                    if ($type === 'PHPStan\MyRule') {
                        return [''];
                    }

                    return [];
                },
            );
        return $mock;
    }

    private function createServiceMapFactoryMock(): ServiceMapFactory
    {
        $service1Mock = $this->createMock(ServiceDefinition::class); // @phpstan-ignore phpstanApi.classConstant
        $service1Mock->method('getClass')
            ->willReturn('Symfony\DicClass1');

        $service2Mock = $this->createMock(ServiceDefinition::class); // @phpstan-ignore phpstanApi.classConstant
        $service2Mock->method('getClass')
            ->willReturn('Symfony\DicClass2');

        $serviceMapMock = $this->createMock(ServiceMap::class); // @phpstan-ignore phpstanApi.classConstant
        $serviceMapMock->method('getServices')
            ->willReturn([$service1Mock, $service2Mock]);

        $factoryMock = $this->createMock(ServiceMapFactory::class); // @phpstan-ignore phpstanApi.classConstant
        $factoryMock->method('create')
            ->willReturn($serviceMapMock);

        return $factoryMock;
    }

}

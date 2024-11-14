<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Collectors\Collector;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;
use PHPStan\Symfony\ServiceMapFactory;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\EntrypointCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Formatter\RemoveDeadCodeFormatter;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use ShipMonk\PHPStan\DeadCode\Provider\DoctrineEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\MethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\NetteEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpStanEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpUnitEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SimpleMethodEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SymfonyEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VendorEntrypointProvider;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use function file_get_contents;
use function is_array;
use function str_replace;
use function substr;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<DeadMethodRule>
 */
class DeadMethodRuleTest extends RuleTestCase
{

    private bool $trackMixedCalls = true;

    private bool $emitErrorsInGroups = true;

    private bool $unwrapGroupedErrors = true;

    private ?DeadMethodRule $rule = null;

    protected function getRule(): DeadMethodRule
    {
        if ($this->rule === null) {
            $this->rule = new DeadMethodRule(
                new ClassHierarchy(),
                !$this->emitErrorsInGroups,
                true,
            );
        }

        return $this->rule;
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [
            new EntrypointCollector($this->getEntrypointProviders()),
            new ClassDefinitionCollector(),
            new MethodCallCollector($this->trackMixedCalls),
            new ConstantFetchCollector(self::createReflectionProvider()),
        ];
    }

    /**
     * @param string|non-empty-list<string> $files
     * @dataProvider provideFiles
     */
    public function testDead($files, ?int $lowestPhpVersion = null): void
    {
        $this->emitErrorsInGroups = false;
        $this->doTestDead($files, $lowestPhpVersion);
    }

    /**
     * @param string|non-empty-list<string> $files
     * @dataProvider provideFiles
     */
    public function testDeadWithGroups($files, ?int $lowestPhpVersion = null): void
    {
        $this->doTestDead($files, $lowestPhpVersion);
    }

    /**
     * @param string|non-empty-list<string> $files
     */
    private function doTestDead($files, ?int $lowestPhpVersion = null): void
    {
        if ($lowestPhpVersion !== null && PHP_VERSION_ID < $lowestPhpVersion) {
            self::markTestSkipped('Requires PHP ' . $lowestPhpVersion);
        }

        $this->analyseFiles(is_array($files) ? $files : [$files]);
    }

    public function testMixedCallsTracked(): void
    {
        $this->analyseFiles([__DIR__ . '/data/DeadMethodRule/methods/mixed/tracked.php']);
    }

    public function testMixedCallsNotTracked(): void
    {
        $this->trackMixedCalls = false;
        $this->analyseFiles([__DIR__ . '/data/DeadMethodRule/methods/mixed/untracked.php']);
    }

    public function testDiagnoseMixedCalls(): void
    {
        $this->analyseFiles([__DIR__ . '/data/DeadMethodRule/methods/mixed/tracked.php']);
        $rule = $this->getRule();

        $actualOutput = '';
        $output = $this->createMock(Output::class);
        $output->expects(self::once())
            ->method('isDebug')
            ->willReturn(true);
        $output->expects(self::atLeastOnce())
            ->method('writeFormatted')
            ->willReturnCallback(
                static function (string $message) use (&$actualOutput): void {
                    $actualOutput .= $message;
                },
            );
        $output->expects(self::atLeastOnce())
            ->method('writeLineFormatted')
            ->willReturnCallback(
                static function (string $message) use (&$actualOutput): void {
                    $actualOutput .= $message . "\n";
                },
            );

        $rule->print($output);

        $ec = ''; // hack editorconfig checker to ignore wrong indentation
        $expectedOutput = <<<"OUTPUT"
        <fg=red>Found 4 usages over unknown type</>:
        $ec • <fg=white>getter1</> method, for example in <fg=white>DeadMixed1\Tester::__construct</>
        $ec • <fg=white>getter2</> method, for example in <fg=white>DeadMixed1\Tester::__construct</>
        $ec • <fg=white>getter3</> method, for example in <fg=white>DeadMixed1\Tester::__construct</>
        $ec • <fg=white>staticMethod</> method, for example in <fg=white>DeadMixed1\Tester::__construct</>

        Thus, any member named the same is considered used, no matter its declaring class!


        OUTPUT;

        self::assertSame($expectedOutput, $actualOutput);
    }

    /**
     * @dataProvider provideAutoRemoveFiles
     */
    public function testAutoRemove(string $file): void
    {
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->expects(self::once())
            ->method('read')
            ->willReturnCallback(
                static function (string $file): string {
                    self::assertFileExists($file);
                    return file_get_contents($file); // @phpstan-ignore return.type
                },
            );
        $fileSystem->expects(self::once())
            ->method('write')
            ->willReturnCallback(
                function (string $file, string $content): void {
                    $expectedFile = $this->getTransformedFilePath($file);
                    self::assertFileExists($expectedFile);

                    $expectedNewCode = file_get_contents($expectedFile);
                    self::assertSame($expectedNewCode, $content);
                },
            );

        $analyserErrors = $this->gatherAnalyserErrors([$file]);

        $formatter = new RemoveDeadCodeFormatter($fileSystem);
        $formatter->formatErrors($this->createAnalysisResult($analyserErrors), $this->createOutput());
    }

    /**
     * @param list<Error> $errors
     */
    private function createAnalysisResult(array $errors): AnalysisResult
    {
        return new AnalysisResult($errors, [], [], [], [], false, null, false, 0, false, []); // @phpstan-ignore phpstanApi.constructor
    }

    private function createOutput(): Output
    {
        return $this->createMock(Output::class);
    }

    public function testGrouping(): void
    {
        $this->unwrapGroupedErrors = false;

        $this->analyse([__DIR__ . '/data/DeadMethodRule/grouping/default.php'], [
            [
                'Unused Grouping\Example::foo',
                23,
                "• Thus Grouping\Example::bar is transitively also unused\n" .
                "• Thus Grouping\Example::bag is transitively also unused\n" .
                '• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused',
            ],
            [
                'Unused Grouping\Example::boo',
                29,
                "• Thus Grouping\Example::bag is transitively also unused\n" .
                "• Thus Grouping\Example::bar is transitively also unused\n" .
                '• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused',
            ],
            [
                'Unused Grouping\Example::UNUSED_CONST',
                8,
            ],
            [
                'Unused Grouping\Example::recur',
                47,
            ],
            [
                'Unused Grouping\Example::recur1',
                54,
                'Thus Grouping\Example::recur2 is transitively also unused',
            ],
        ]);
    }

    /**
     * @return array<string, array{0: string|list<string>, 1?: int}>
     */
    public static function provideFiles(): iterable
    {
        // methods
        yield 'method-anonym' => [__DIR__ . '/data/DeadMethodRule/methods/anonym.php'];
        yield 'method-enum' => [__DIR__ . '/data/DeadMethodRule/methods/enum.php', 8_01_00];
        yield 'method-callables' => [__DIR__ . '/data/DeadMethodRule/methods/callables.php'];
        yield 'method-code' => [__DIR__ . '/data/DeadMethodRule/methods/basic.php'];
        yield 'method-ctor' => [__DIR__ . '/data/DeadMethodRule/methods/ctor.php'];
        yield 'method-ctor-interface' => [__DIR__ . '/data/DeadMethodRule/methods/ctor-interface.php'];
        yield 'method-ctor-private' => [__DIR__ . '/data/DeadMethodRule/methods/ctor-private.php'];
        yield 'method-ctor-denied' => [__DIR__ . '/data/DeadMethodRule/methods/ctor-denied.php'];
        yield 'method-ctor-missing' => [__DIR__ . '/data/DeadMethodRule/methods/ctor-missing.php'];
        yield 'method-cycles' => [__DIR__ . '/data/DeadMethodRule/methods/cycles.php'];
        yield 'method-abstract-1' => [__DIR__ . '/data/DeadMethodRule/methods/abstract-1.php'];
        yield 'method-entrypoint' => [__DIR__ . '/data/DeadMethodRule/methods/entrypoint.php'];
        yield 'method-clone' => [__DIR__ . '/data/DeadMethodRule/methods/clone.php'];
        yield 'method-magic' => [__DIR__ . '/data/DeadMethodRule/methods/magic.php'];
        yield 'method-mixed' => [__DIR__ . '/data/DeadMethodRule/methods/mixed/tracked.php'];
        yield 'method-new-in-initializers' => [__DIR__ . '/data/DeadMethodRule/methods/new-in-initializers.php'];
        yield 'method-first-class-callable' => [__DIR__ . '/data/DeadMethodRule/methods/first-class-callable.php'];
        yield 'method-overwriting-1' => [__DIR__ . '/data/DeadMethodRule/methods/overwriting-methods-1.php'];
        yield 'method-overwriting-2' => [__DIR__ . '/data/DeadMethodRule/methods/overwriting-methods-2.php'];
        yield 'method-overwriting-3' => [__DIR__ . '/data/DeadMethodRule/methods/overwriting-methods-3.php'];
        yield 'method-overwriting-4' => [__DIR__ . '/data/DeadMethodRule/methods/overwriting-methods-4.php'];
        yield 'method-overwriting-5' => [__DIR__ . '/data/DeadMethodRule/methods/overwriting-methods-5.php'];
        yield 'method-trait-abstract' => [__DIR__ . '/data/DeadMethodRule/methods/traits-abstract-method.php'];
        yield 'method-trait-1' => [__DIR__ . '/data/DeadMethodRule/methods/traits-1.php'];
        yield 'method-trait-2' => [__DIR__ . '/data/DeadMethodRule/methods/traits-2.php'];
        yield 'method-trait-3' => [__DIR__ . '/data/DeadMethodRule/methods/traits-3.php'];
        yield 'method-trait-4' => [__DIR__ . '/data/DeadMethodRule/methods/traits-4.php'];
        yield 'method-trait-5' => [__DIR__ . '/data/DeadMethodRule/methods/traits-5.php'];
        yield 'method-trait-6' => [__DIR__ . '/data/DeadMethodRule/methods/traits-6.php'];
        yield 'method-trait-7' => [__DIR__ . '/data/DeadMethodRule/methods/traits-7.php'];
        yield 'method-trait-8' => [__DIR__ . '/data/DeadMethodRule/methods/traits-8.php'];
        yield 'method-trait-9' => [__DIR__ . '/data/DeadMethodRule/methods/traits-9.php'];
        yield 'method-trait-10' => [__DIR__ . '/data/DeadMethodRule/methods/traits-10.php'];
        yield 'method-trait-11' => [[__DIR__ . '/data/DeadMethodRule/methods/traits-11-a.php', __DIR__ . '/data/DeadMethodRule/methods/traits-11-b.php']];
        yield 'method-trait-12' => [__DIR__ . '/data/DeadMethodRule/methods/traits-12.php'];
        yield 'method-trait-13' => [__DIR__ . '/data/DeadMethodRule/methods/traits-13.php'];
        yield 'method-trait-14' => [__DIR__ . '/data/DeadMethodRule/methods/traits-14.php'];
        yield 'method-trait-15' => [__DIR__ . '/data/DeadMethodRule/methods/traits-15.php'];
        yield 'method-trait-16' => [__DIR__ . '/data/DeadMethodRule/methods/traits-16.php'];
        yield 'method-trait-17' => [__DIR__ . '/data/DeadMethodRule/methods/traits-17.php'];
        yield 'method-trait-18' => [__DIR__ . '/data/DeadMethodRule/methods/traits-18.php'];
        yield 'method-trait-19' => [__DIR__ . '/data/DeadMethodRule/methods/traits-19.php'];
        yield 'method-trait-20' => [__DIR__ . '/data/DeadMethodRule/methods/traits-20.php'];
        yield 'method-trait-21' => [__DIR__ . '/data/DeadMethodRule/methods/traits-21.php'];
        yield 'method-trait-22' => [__DIR__ . '/data/DeadMethodRule/methods/traits-22.php'];
        yield 'method-trait-23' => [__DIR__ . '/data/DeadMethodRule/methods/traits-23.php'];
        yield 'method-nullsafe' => [__DIR__ . '/data/DeadMethodRule/methods/nullsafe.php'];
        yield 'method-dead-in-parent-1' => [__DIR__ . '/data/DeadMethodRule/methods/dead-in-parent-1.php'];
        yield 'method-indirect-interface' => [__DIR__ . '/data/DeadMethodRule/methods/indirect-interface.php'];
        yield 'method-parent-call-1' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-1.php'];
        yield 'method-parent-call-2' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-2.php'];
        yield 'method-parent-call-3' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-3.php'];
        yield 'method-parent-call-4' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-4.php'];
        yield 'method-parent-call-5' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-5.php'];
        yield 'method-parent-call-6' => [__DIR__ . '/data/DeadMethodRule/methods/parent-call-6.php'];
        yield 'method-attribute' => [__DIR__ . '/data/DeadMethodRule/methods/attribute.php'];
        yield 'method-dynamic-method' => [__DIR__ . '/data/DeadMethodRule/methods/dynamic-method.php'];
        yield 'method-call-on-class-string' => [__DIR__ . '/data/DeadMethodRule/methods/class-string.php'];
        yield 'method-array-map-1' => [__DIR__ . '/data/DeadMethodRule/methods/array-map-1.php'];
        yield 'method-unknown-class' => [__DIR__ . '/data/DeadMethodRule/methods/unknown-class.php'];

        // method providers
        yield 'provider-vendor' => [__DIR__ . '/data/DeadMethodRule/providers/vendor.php'];
        yield 'provider-symfony' => [__DIR__ . '/data/DeadMethodRule/providers/symfony.php', 8_00_00];
        yield 'provider-phpunit' => [__DIR__ . '/data/DeadMethodRule/providers/phpunit.php', 8_00_00];
        yield 'provider-doctrine' => [__DIR__ . '/data/DeadMethodRule/providers/doctrine.php', 8_00_00];
        yield 'provider-phpstan' => [__DIR__ . '/data/DeadMethodRule/providers/phpstan.php'];
        yield 'provider-nette' => [__DIR__ . '/data/DeadMethodRule/providers/nette.php'];

        // constants
        yield 'const-basic' => [__DIR__ . '/data/DeadMethodRule/constants/basic.php'];
        yield 'const-function' => [__DIR__ . '/data/DeadMethodRule/constants/constant-function.php'];
        yield 'const-dynamic' => [__DIR__ . '/data/DeadMethodRule/constants/dynamic.php'];
        yield 'const-expr' => [__DIR__ . '/data/DeadMethodRule/constants/expr.php'];
        yield 'const-mixed' => [__DIR__ . '/data/DeadMethodRule/constants/mixed.php'];
        yield 'const-override' => [__DIR__ . '/data/DeadMethodRule/constants/override.php'];
        yield 'const-static' => [__DIR__ . '/data/DeadMethodRule/constants/static.php'];
        yield 'const-traits-1' => [__DIR__ . '/data/DeadMethodRule/constants/traits-1.php'];
        yield 'const-traits-2' => [__DIR__ . '/data/DeadMethodRule/constants/traits-2.php'];
        yield 'const-traits-3' => [__DIR__ . '/data/DeadMethodRule/constants/traits-3.php'];
        yield 'const-traits-5' => [__DIR__ . '/data/DeadMethodRule/constants/traits-5.php'];
        yield 'const-traits-6' => [__DIR__ . '/data/DeadMethodRule/constants/traits-6.php'];
        yield 'const-traits-7' => [__DIR__ . '/data/DeadMethodRule/constants/traits-7.php'];
        yield 'const-traits-9' => [__DIR__ . '/data/DeadMethodRule/constants/traits-9.php'];
        yield 'const-traits-10' => [__DIR__ . '/data/DeadMethodRule/constants/traits-10.php'];
        yield 'const-traits-11' => [[__DIR__ . '/data/DeadMethodRule/constants/traits-11-a.php', __DIR__ . '/data/DeadMethodRule/constants/traits-11-b.php']];
        yield 'const-traits-13' => [__DIR__ . '/data/DeadMethodRule/constants/traits-13.php'];
        yield 'const-traits-14' => [__DIR__ . '/data/DeadMethodRule/constants/traits-14.php'];
        yield 'const-traits-21' => [__DIR__ . '/data/DeadMethodRule/constants/traits-21.php'];
        yield 'const-traits-23' => [__DIR__ . '/data/DeadMethodRule/constants/traits-23.php'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function provideAutoRemoveFiles(): iterable
    {
        yield 'sample' => [__DIR__ . '/data/DeadMethodRule/removing/sample.php'];
        yield 'no-namespace' => [__DIR__ . '/data/DeadMethodRule/removing/no-namespace.php'];
    }

    private function getTransformedFilePath(string $file): string
    {
        return str_replace('.php', '.transformed.php', $file);
    }

    /**
     * @return list<MethodEntrypointProvider>
     */
    private function getEntrypointProviders(): array
    {
        return [
            new class extends SimpleMethodEntrypointProvider
            {

                public function isEntrypointMethod(ReflectionMethod $method): bool
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
        $service1Mock = $this->createMock(ServiceDefinition::class);
        $service1Mock->method('getClass')
            ->willReturn('Symfony\DicClass1');

        $service2Mock = $this->createMock(ServiceDefinition::class);
        $service2Mock->method('getClass')
            ->willReturn('Symfony\DicClass2');

        $serviceMapMock = $this->createMock(ServiceMap::class);
        $serviceMapMock->method('getServices')
            ->willReturn([$service1Mock, $service2Mock]);

        $factoryMock = $this->createMock(ServiceMapFactory::class); // @phpstan-ignore phpstanApi.classConstant
        $factoryMock->method('create')
            ->willReturn($serviceMapMock);

        return $factoryMock;
    }

    public function gatherAnalyserErrors(array $files): array
    {
        if (!$this->unwrapGroupedErrors) {
            return parent::gatherAnalyserErrors($files);
        }

        $result = [];
        $errors = parent::gatherAnalyserErrors($files);

        foreach ($errors as $error) {
            $result[] = $error;

            /** @var array<string, array{file: string, line: int}> $metadata */
            $metadata = $error->getMetadata();

            foreach ($metadata as $alsoDead => ['file' => $file, 'line' => $line, 'transitive' => $transitive]) {
                if (!$transitive) {
                    continue;
                }

                $ref = substr($alsoDead, 2); // TODO remove hack

                // @phpstan-ignore phpstanApi.constructor
                $result[] = new Error(
                    "Unused $ref",
                    $file,
                    $line,
                    true,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $error->getIdentifier(),
                );
            }
        }

        return $result;
    }

}

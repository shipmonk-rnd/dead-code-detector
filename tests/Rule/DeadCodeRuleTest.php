<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Excluder\TestsUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Formatter\RemoveDeadCodeFormatter;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOriginDetector;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use ShipMonk\PHPStan\DeadCode\Provider\DoctrineUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\NetteUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpStanUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpUnitUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SymfonyUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VendorUsageProvider;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use function file_get_contents;
use function is_array;
use function str_replace;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<DeadCodeRule>
 */
class DeadCodeRuleTest extends RuleTestCase
{

    private bool $trackMixedAccess = true;

    private bool $emitErrorsInGroups = true;

    private bool $unwrapGroupedErrors = true;

    private ?DeadCodeRule $rule = null;

    protected function getRule(): DeadCodeRule
    {
        if ($this->rule === null) {
            $this->rule = new DeadCodeRule(
                new ClassHierarchy(),
                !$this->emitErrorsInGroups,
                true,
                new BackwardCompatibilityChecker([]),
            );
        }

        return $this->rule;
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        $reflectionProvider = self::createReflectionProvider();

        return [
            new ProvidedUsagesCollector($reflectionProvider, $this->getMemberUsageProviders(), $this->getMemberUsageExcluders()),
            new ClassDefinitionCollector(self::createReflectionProvider()),
            new MethodCallCollector($this->createUsageOriginDetector(), $this->trackMixedAccess, $this->getMemberUsageExcluders()),
            new ConstantFetchCollector($this->createUsageOriginDetector(), $reflectionProvider, $this->trackMixedAccess, $this->getMemberUsageExcluders()),
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
        $this->analyseFiles([__DIR__ . '/data/methods/mixed/tracked.php']);
        $this->analyseFiles([__DIR__ . '/data/constants/mixed/tracked.php']);
    }

    public function testMixedCallsNotTracked(): void
    {
        $this->trackMixedAccess = false;
        $this->analyseFiles([__DIR__ . '/data/methods/mixed/untracked.php']);
        $this->analyseFiles([__DIR__ . '/data/constants/mixed/untracked.php']);
    }

    public function testDiagnoseMixedCalls(): void
    {
        $this->analyseFiles([__DIR__ . '/data/methods/mixed/tracked.php']);
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

    /**
     * @param string|list<string> $files
     * @param list<array{0: string, 1: int, 2?: string|null}> $expectedErrors
     * @dataProvider provideGroupingFiles
     */
    public function testGrouping($files, array $expectedErrors): void
    {
        $this->unwrapGroupedErrors = false;

        $this->analyse(is_array($files) ? $files : [$files], $expectedErrors);
    }

    /**
     * @return iterable<string, array{0: string|list<string>, 1: list<array{0: string, 1: int, 2?: string|null}>}>
     */
    public static function provideGroupingFiles(): iterable
    {
        yield 'default' => [
            __DIR__ . '/data/grouping/default.php',
            [
                [
                    'Unused Grouping\Example::UNUSED_CONST',
                    8,
                ],
                [
                    'Unused Grouping\Example::boo',
                    29,
                    "• Thus Grouping\Example::bag is transitively also unused\n" .
                    "• Thus Grouping\Example::bar is transitively also unused\n" .
                    '• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused',
                ],
                [
                    'Unused Grouping\Example::foo',
                    23,
                    "• Thus Grouping\Example::bar is transitively also unused\n" .
                    "• Thus Grouping\Example::bag is transitively also unused\n" .
                    '• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused',
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
            ],
        ];

        yield 'closure' => [
            __DIR__ . '/data/grouping/closure.php',
            [
                [
                    'Unused GroupingClosure\ClosureUser::__construct',
                    12,
                    'Thus GroupingClosure\Incriminated::baz is transitively also unused',
                ],
            ],
        ];

        yield 'repeated' => [
            __DIR__ . '/data/grouping/repeated.php',
            [
                [
                    'Unused GroupingRepeated\User::__construct',
                    12,
                    'Thus GroupingRepeated\Incriminated::baz is transitively also unused',
                ],
            ],
        ];

        yield 'order-1' => [
            [__DIR__ . '/data/grouping/order/one.php', __DIR__ . '/data/grouping/order/two.php'],
            [
                [
                    'Unused GroupingOrder\ClassOne::one',
                    7,
                    'Thus GroupingOrder\ClassTwo::two is transitively also unused',
                ],
            ],
        ];

        yield 'order-2' => [
            [__DIR__ . '/data/grouping/order/two.php', __DIR__ . '/data/grouping/order/one.php'],
            [
                [
                    'Unused GroupingOrder\ClassOne::one', // ensure deterministic representative is chosen (even when ClassTwo was analysed first)
                    7,
                    'Thus GroupingOrder\ClassTwo::two is transitively also unused',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{0: string|list<string>, 1?: int}>
     */
    public static function provideFiles(): iterable
    {
        // methods
        yield 'method-anonym' => [__DIR__ . '/data/methods/anonym.php'];
        yield 'method-enum' => [__DIR__ . '/data/methods/enum.php', 8_01_00];
        yield 'method-callables' => [__DIR__ . '/data/methods/callables.php'];
        yield 'method-code' => [__DIR__ . '/data/methods/basic.php'];
        yield 'method-ctor' => [__DIR__ . '/data/methods/ctor.php'];
        yield 'method-ctor-interface' => [__DIR__ . '/data/methods/ctor-interface.php'];
        yield 'method-ctor-private' => [__DIR__ . '/data/methods/ctor-private.php'];
        yield 'method-ctor-denied' => [__DIR__ . '/data/methods/ctor-denied.php'];
        yield 'method-ctor-missing' => [__DIR__ . '/data/methods/ctor-missing.php'];
        yield 'method-cycles' => [__DIR__ . '/data/methods/cycles.php'];
        yield 'method-abstract-1' => [__DIR__ . '/data/methods/abstract-1.php'];
        yield 'method-abstract-2' => [__DIR__ . '/data/methods/abstract-2.php'];
        yield 'method-entrypoint' => [__DIR__ . '/data/methods/entrypoint.php'];
        yield 'method-clone' => [__DIR__ . '/data/methods/clone.php'];
        yield 'method-magic' => [__DIR__ . '/data/methods/magic.php'];
        yield 'method-mixed' => [__DIR__ . '/data/methods/mixed/tracked.php'];
        yield 'method-new-in-initializers' => [__DIR__ . '/data/methods/new-in-initializers.php'];
        yield 'method-first-class-callable' => [__DIR__ . '/data/methods/first-class-callable.php'];
        yield 'method-hierarchy-in-vendor' => [__DIR__ . '/data/methods/hierarchy-in-vendor.php'];
        yield 'method-overwriting-1' => [__DIR__ . '/data/methods/overwriting-methods-1.php'];
        yield 'method-overwriting-2' => [__DIR__ . '/data/methods/overwriting-methods-2.php'];
        yield 'method-overwriting-3' => [__DIR__ . '/data/methods/overwriting-methods-3.php'];
        yield 'method-overwriting-4' => [__DIR__ . '/data/methods/overwriting-methods-4.php'];
        yield 'method-overwriting-5' => [__DIR__ . '/data/methods/overwriting-methods-5.php'];
        yield 'method-trait-abstract' => [__DIR__ . '/data/methods/traits-abstract-method.php'];
        yield 'method-trait-1' => [__DIR__ . '/data/methods/traits-1.php'];
        yield 'method-trait-2' => [__DIR__ . '/data/methods/traits-2.php'];
        yield 'method-trait-3' => [__DIR__ . '/data/methods/traits-3.php'];
        yield 'method-trait-4' => [__DIR__ . '/data/methods/traits-4.php'];
        yield 'method-trait-5' => [__DIR__ . '/data/methods/traits-5.php'];
        yield 'method-trait-6' => [__DIR__ . '/data/methods/traits-6.php'];
        yield 'method-trait-7' => [__DIR__ . '/data/methods/traits-7.php'];
        yield 'method-trait-8' => [__DIR__ . '/data/methods/traits-8.php'];
        yield 'method-trait-9' => [__DIR__ . '/data/methods/traits-9.php'];
        yield 'method-trait-10' => [__DIR__ . '/data/methods/traits-10.php'];
        yield 'method-trait-11' => [[__DIR__ . '/data/methods/traits-11-a.php', __DIR__ . '/data/methods/traits-11-b.php']];
        yield 'method-trait-12' => [__DIR__ . '/data/methods/traits-12.php'];
        yield 'method-trait-13' => [__DIR__ . '/data/methods/traits-13.php'];
        yield 'method-trait-14' => [__DIR__ . '/data/methods/traits-14.php'];
        yield 'method-trait-15' => [__DIR__ . '/data/methods/traits-15.php'];
        yield 'method-trait-16' => [__DIR__ . '/data/methods/traits-16.php'];
        yield 'method-trait-17' => [__DIR__ . '/data/methods/traits-17.php'];
        yield 'method-trait-18' => [__DIR__ . '/data/methods/traits-18.php'];
        yield 'method-trait-19' => [__DIR__ . '/data/methods/traits-19.php'];
        yield 'method-trait-20' => [__DIR__ . '/data/methods/traits-20.php'];
        yield 'method-trait-21' => [__DIR__ . '/data/methods/traits-21.php'];
        yield 'method-trait-22' => [__DIR__ . '/data/methods/traits-22.php'];
        yield 'method-trait-23' => [__DIR__ . '/data/methods/traits-23.php'];
        yield 'method-nullsafe' => [__DIR__ . '/data/methods/nullsafe.php'];
        yield 'method-parent-1' => [__DIR__ . '/data/methods/parent-1.php'];
        yield 'method-parent-2' => [__DIR__ . '/data/methods/parent-2.php'];
        yield 'method-indirect-interface' => [__DIR__ . '/data/methods/indirect-interface.php'];
        yield 'method-indirect-interface-2' => [__DIR__ . '/data/methods/indirect-interface-2.php'];
        yield 'method-indirect-interface-3' => [__DIR__ . '/data/methods/indirect-interface-3.php'];
        yield 'method-indirect-interface-4' => [__DIR__ . '/data/methods/indirect-interface-4.php'];
        yield 'method-indirect-interface-5' => [__DIR__ . '/data/methods/indirect-interface-5.php'];
        yield 'method-indirect-interface-6' => [__DIR__ . '/data/methods/indirect-interface-6.php'];
        yield 'method-indirect-interface-7' => [__DIR__ . '/data/methods/indirect-interface-7.php'];
        yield 'method-indirect-interface-8' => [__DIR__ . '/data/methods/indirect-interface-8.php'];
        yield 'method-parent-call-1' => [__DIR__ . '/data/methods/parent-call-1.php'];
        yield 'method-parent-call-2' => [__DIR__ . '/data/methods/parent-call-2.php'];
        yield 'method-parent-call-3' => [__DIR__ . '/data/methods/parent-call-3.php'];
        yield 'method-parent-call-4' => [__DIR__ . '/data/methods/parent-call-4.php'];
        yield 'method-parent-call-5' => [__DIR__ . '/data/methods/parent-call-5.php'];
        yield 'method-parent-call-6' => [__DIR__ . '/data/methods/parent-call-6.php'];
        yield 'method-attribute' => [__DIR__ . '/data/methods/attribute.php'];
        yield 'method-dynamic-method' => [__DIR__ . '/data/methods/dynamic-method.php'];
        yield 'method-call-on-class-string' => [__DIR__ . '/data/methods/class-string.php'];
        yield 'method-array-map-1' => [__DIR__ . '/data/methods/array-map-1.php'];
        yield 'method-unknown-class' => [__DIR__ . '/data/methods/unknown-class.php'];

        // providers
        yield 'provider-vendor' => [__DIR__ . '/data/providers/vendor.php'];
        yield 'provider-reflection' => [__DIR__ . '/data/providers/reflection.php', 8_01_00];
        yield 'provider-symfony' => [__DIR__ . '/data/providers/symfony.php', 8_00_00];
        yield 'provider-phpunit' => [__DIR__ . '/data/providers/phpunit.php', 8_00_00];
        yield 'provider-doctrine' => [__DIR__ . '/data/providers/doctrine.php', 8_00_00];
        yield 'provider-phpstan' => [__DIR__ . '/data/providers/phpstan.php'];
        yield 'provider-nette' => [__DIR__ . '/data/providers/nette.php'];

        // excluders
        yield 'excluder-tests' => [[__DIR__ . '/data/excluders/tests/src/code.php', __DIR__ . '/data/excluders/tests/tests/code.php']];
        yield 'excluder-mixed' => [__DIR__ . '/data/excluders/mixed/code.php'];

        // constants
        yield 'const-basic' => [__DIR__ . '/data/constants/basic.php'];
        yield 'const-function' => [__DIR__ . '/data/constants/constant-function.php'];
        yield 'const-dynamic' => [__DIR__ . '/data/constants/dynamic.php'];
        yield 'const-expr' => [__DIR__ . '/data/constants/expr.php'];
        yield 'const-magic' => [__DIR__ . '/data/constants/magic.php'];
        yield 'const-mixed' => [__DIR__ . '/data/constants/mixed/tracked.php'];
        yield 'const-override' => [__DIR__ . '/data/constants/override.php'];
        yield 'const-static' => [__DIR__ . '/data/constants/static.php'];
        yield 'const-traits-1' => [__DIR__ . '/data/constants/traits-1.php'];
        yield 'const-traits-2' => [__DIR__ . '/data/constants/traits-2.php'];
        yield 'const-traits-3' => [__DIR__ . '/data/constants/traits-3.php'];
        yield 'const-traits-5' => [__DIR__ . '/data/constants/traits-5.php'];
        yield 'const-traits-6' => [__DIR__ . '/data/constants/traits-6.php'];
        yield 'const-traits-7' => [__DIR__ . '/data/constants/traits-7.php'];
        yield 'const-traits-9' => [__DIR__ . '/data/constants/traits-9.php'];
        yield 'const-traits-10' => [__DIR__ . '/data/constants/traits-10.php'];
        yield 'const-traits-11' => [[__DIR__ . '/data/constants/traits-11-a.php', __DIR__ . '/data/constants/traits-11-b.php']];
        yield 'const-traits-13' => [__DIR__ . '/data/constants/traits-13.php'];
        yield 'const-traits-14' => [__DIR__ . '/data/constants/traits-14.php'];
        yield 'const-traits-21' => [__DIR__ . '/data/constants/traits-21.php'];
        yield 'const-traits-23' => [__DIR__ . '/data/constants/traits-23.php'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function provideAutoRemoveFiles(): iterable
    {
        yield 'sample' => [__DIR__ . '/data/removing/sample.php'];
        yield 'no-namespace' => [__DIR__ . '/data/removing/no-namespace.php'];
    }

    private function getTransformedFilePath(string $file): string
    {
        return str_replace('.php', '.transformed.php', $file);
    }

    /**
     * @return list<MemberUsageProvider>
     */
    private function getMemberUsageProviders(): array
    {
        return [
            new ReflectionUsageProvider(
                $this->createUsageOriginDetector(),
                true,
            ),
            new class extends ReflectionBasedMemberUsageProvider
            {

                public function shouldMarkMethodAsUsed(ReflectionMethod $method): bool
                {
                    return $method->getDeclaringClass()->getName() === 'DeadEntrypoint\Entrypoint';
                }

            },
            new VendorUsageProvider(
                true,
            ),
            new PhpUnitUsageProvider(
                true,
                self::getContainer()->getByType(PhpDocParser::class),
                self::getContainer()->getByType(Lexer::class),
            ),
            new DoctrineUsageProvider(
                true,
            ),
            new PhpStanUsageProvider(
                true,
                $this->createPhpStanContainerMock(),
            ),
            new NetteUsageProvider(
                self::getContainer()->getByType(ReflectionProvider::class),
                true,
            ),
            new SymfonyUsageProvider(
                $this->createContainerMockWithSymfonyConfig(),
                true,
                __DIR__ . '/data/providers/symfony/',
            ),
        ];
    }

    /**
     * @return list<MemberUsageExcluder>
     */
    private function getMemberUsageExcluders(): array
    {
        return [
            new TestsUsageExcluder(
                self::createReflectionProvider(),
                true,
                [__DIR__ . '/data/excluders/tests/tests'],
            ),
            new class implements MemberUsageExcluder
            {

                public function getIdentifier(): string
                {
                    return 'mixed';
                }

                public function shouldExclude(ClassMemberUsage $usage, Node $node, Scope $scope): bool
                {
                    return $usage->getMemberRef()->getMemberName() === 'mixed';
                }

            },
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

    private function createUsageOriginDetector(): UsageOriginDetector
    {
        /** @var UsageOriginDetector|null $detector */
        static $detector = null;

        if ($detector === null) {
            $detector = new UsageOriginDetector();
        }

        return $detector;
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

                // @phpstan-ignore phpstanApi.constructor
                $result[] = new Error(
                    "Unused $alsoDead",
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

    private function createContainerMockWithSymfonyConfig(): Container
    {
        $mock = $this->createMock(Container::class);

        $mock->expects(self::once())
            ->method('getParameter')
            ->willReturn(['containerXmlPath' => __DIR__ . '/data/providers/symfony/services.xml']);

        return $mock;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\DependencyInjection\Container;
use PHPStan\File\SimpleRelativePathHelper;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Debug\DebugUsagePrinter;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Excluder\MixedUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Excluder\TestsUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Formatter\RemoveDeadCodeFormatter;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use ShipMonk\PHPStan\DeadCode\Output\OutputEnhancer;
use ShipMonk\PHPStan\DeadCode\Provider\DoctrineUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\NetteUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpStanUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\PhpUnitUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\SymfonyUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VendorUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use ShipMonk\PHPStan\DeadCode\Transformer\FileSystem;
use Throwable;
use Traversable;
use function array_merge;
use function error_reporting;
use function file_get_contents;
use function is_array;
use function iterator_to_array;
use function ob_end_clean;
use function ob_start;
use function preg_replace;
use function str_replace;
use function strpos;
use const E_ALL;
use const E_DEPRECATED;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<DeadCodeRule>
 */
class DeadCodeRuleTest extends RuleTestCase
{

    /**
     * @var list<string>
     */
    private array $debugMembers = [];

    private bool $trackMixedAccess = true;

    private bool $emitErrorsInGroups = true;

    private bool $unwrapGroupedErrors = true;

    private ?DeadCodeRule $rule = null;

    private ?string $editorUrl = null;

    protected function getRule(): DeadCodeRule
    {
        $container = $this->createMock(Container::class);
        $container->expects(self::any())
            ->method('getParameter')
            ->willReturn(['debug' => ['usagesOf' => $this->debugMembers]]);

        if ($this->rule === null) {
            $this->rule = new DeadCodeRule(
                new DebugUsagePrinter(
                    $container,
                    $this->createOutputEnhancer(),
                    self::createReflectionProvider(),
                    !$this->trackMixedAccess,
                ),
                new ClassHierarchy(),
                !$this->emitErrorsInGroups,
                new BackwardCompatibilityChecker([], null),
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
            new ClassDefinitionCollector($reflectionProvider),
            new MethodCallCollector($this->getMemberUsageExcluders()),
            new ConstantFetchCollector($reflectionProvider, $this->getMemberUsageExcluders()),
        ];
    }

    /**
     * @param string|non-empty-list<string> $files
     * @dataProvider provideFiles
     */
    public function testDead($files, bool $requirementsMet = true): void
    {
        $this->emitErrorsInGroups = false;
        $this->doTestDead($files, $requirementsMet);
    }

    /**
     * @param string|non-empty-list<string> $files
     * @dataProvider provideFiles
     */
    public function testDeadWithGroups($files, bool $requirementsMet = true): void
    {
        $this->doTestDead($files, $requirementsMet);
    }

    /**
     * Ensure we test real PHP code
     * - mainly targets invalid class/trait/iface compositions
     *
     * @runInSeparateProcess
     */
    public function testNoFatalError(): void
    {
        if (PHP_VERSION_ID < 8_04_00) {
            self::markTestSkipped('Requires PHP 8.4+ to allow any PHP feature in test code');
        }

        // when lowest versions are installed, we get "Implicitly marking parameter xxx as nullable is deprecated" for symfony deps
        error_reporting(E_ALL & ~E_DEPRECATED);

        $required = [];

        $fileProviders = array_merge(
            iterator_to_array(self::provideFiles(), false),
            iterator_to_array(self::provideGroupingFiles(), false),
            iterator_to_array(self::provideAutoRemoveFiles(), false),
        );

        foreach ($fileProviders as $args) {
            $files = is_array($args[0]) ? $args[0] : [$args[0]];

            foreach ($files as $file) {
                if (isset($required[$file])) {
                    continue;
                }

                try {
                    ob_start();
                    require $file;
                    ob_end_clean();

                } catch (Throwable $e) {
                    self::fail("Fatal error in {$e->getFile()}:{$e->getLine()}:\n {$e->getMessage()}");
                }

                $required[$file] = true;
            }
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param string|non-empty-list<string> $files
     */
    private function doTestDead($files, bool $requirementsMet): void
    {
        if (!$requirementsMet) {
            self::markTestSkipped('Requirements not met');
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
        $rule->print($this->getOutputMock($actualOutput));

        $ec = ''; // hack editorconfig checker to ignore wrong indentation
        $expectedOutput = <<<"OUTPUT"
        <fg=red>Found 4 usages over unknown type</>:
        $ec • <fg=white>getter1</> method, for example in <fg=white>data/methods/mixed/tracked.php:46</>
        $ec • <fg=white>getter2</> method, for example in <fg=white>data/methods/mixed/tracked.php:49</>
        $ec • <fg=white>getter3</> method, for example in <fg=white>data/methods/mixed/tracked.php:52</>
        $ec • <fg=white>staticMethod</> method, for example in <fg=white>data/methods/mixed/tracked.php:57</>

        Thus, any member named the same is considered used, no matter its declaring class!


        OUTPUT;

        self::assertSame($expectedOutput, $actualOutput);
    }

    public function testDebugUsage(): void
    {
        $this->debugMembers = [
            'DateTime::format',
            'DebugAlternative\Foo::foo',
            'DebugCtor\Foo::__construct',
            'DebugExclude\Foo::mixedExcluder1',
            'DebugExclude\Foo::mixedExcluder2',
            'DebugNever\Foo::__get',
            'DebugVirtual\FooTest::testFoo',
            'DebugGlobal\Foo::chain2',
            'DebugMixed\Foo::any',
            'DebugCycle\Foo::__construct',
            'DebugRegular\Another::call',
            'DebugUnsupported\Foo::notDead',
            'DebugZero\Foo::__construct',
        ];
        $this->analyseFiles([
            __DIR__ . '/data/debug/alternative.php',
            __DIR__ . '/data/debug/ctor.php',
            __DIR__ . '/data/debug/exclude.php',
            __DIR__ . '/data/debug/cycle.php',
            __DIR__ . '/data/debug/foreign.php',
            __DIR__ . '/data/debug/global.php',
            __DIR__ . '/data/debug/mixed.php',
            __DIR__ . '/data/debug/never.php',
            __DIR__ . '/data/debug/regular.php',
            __DIR__ . '/data/debug/unsupported.php',
            __DIR__ . '/data/debug/virtual.php',
            __DIR__ . '/data/debug/zero.php',
        ]);
        $rule = $this->getRule();

        $actualOutput = '';
        $rule->print($this->getOutputMock($actualOutput));

        $expectedOutput = file_get_contents(__DIR__ . '/data/debug/expected_output.txt');
        self::assertNotFalse($expectedOutput);
        self::assertSame($expectedOutput . "\n", $this->trimFgColors($actualOutput));
    }

    public function testDebugUsageWithExcludedMixed(): void
    {
        $this->trackMixedAccess = false;
        $this->debugMembers = ['DebugMixed\Foo::any'];
        $this->analyse([__DIR__ . '/data/debug/mixed.php'], [
            [
                'Unused DebugMixed\Foo::any (all usages excluded by usageOverMixed excluder)',
                7,
            ],
        ]);
        $rule = $this->getRule();

        $actualOutput = '';
        $rule->print($this->getOutputMock($actualOutput));
        $expectedOutput = <<<'OUTPUT'

        Usage debugging information:

        DebugMixed\Foo::any
        |
        | Dead because:
        | all usages are excluded
        |
        | Found 1 usage:
        |  • data/debug/mixed.php:13 - excluded by usageOverMixed excluder

        OUTPUT;

        self::assertSame($expectedOutput . "\n", $this->trimFgColors($actualOutput));
    }

    public function testDebugUsageOriginLink(): void
    {
        $this->editorUrl = '( %relFile% at line %line% )';
        $this->debugMembers = ['DebugTrait\User::foo'];

        $this->analyse([__DIR__ . '/data/debug/trait-1.php', __DIR__ . '/data/debug/trait-2.php'], []);
        $rule = $this->getRule();

        $actualOutput = '';
        $rule->print($this->getOutputMock($actualOutput));
        $expectedOutput = <<<'OUTPUT'

        Usage debugging information:

        DebugTrait\User::foo
        |
        | Marked as alive at:
        | entry <href=( data/debug/trait-2.php at line 11 )>data/debug/trait-2.php:11</>
        |   calls <href=( data/debug/trait-1.php at line 10 )>DebugTrait\TrueOrigin::origin:10</>
        |     calls DebugTrait\User::foo
        |
        | Found 1 usage:
        |  • <href=( data/debug/trait-1.php at line 10 )>data/debug/trait-1.php:10</>

        OUTPUT;

        self::assertSame($expectedOutput . "\n", $this->trimFgColors($actualOutput));
    }

    /**
     * @dataProvider provideDebugUsageInvalidArgs
     */
    public function testDebugUsageInvalidArgs(string $member, string $error): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($error);

        $this->debugMembers = [$member];
        $this->analyseFiles([__DIR__ . '/data/debug/alternative.php']);
        $this->getRule();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideDebugUsageInvalidArgs(): array
    {
        return [
            'method not owned' => ['DebugAlternative\Clazz::foo', "Member 'foo' does not exist directly in 'DebugAlternative\Clazz'"],
            'method not declared' => ['DebugAlternative\Clazz::__construct', "Member '__construct' does not exist directly in 'DebugAlternative\Clazz'"],
            'no method' => ['DebugAlternative\Clazz::xyz', "Member 'xyz' does not exist directly in 'DebugAlternative\Clazz'"],
            'no class' => ['InvalidClass::foo', "Class 'InvalidClass' does not exist"],
            'invalid format' => ['InvalidFormat', "Invalid debug member format: 'InvalidFormat', expected 'ClassName::memberName'"],
        ];
    }

    private function getOutputMock(string &$actualOutput): Output
    {
        $output = $this->createMock(Output::class);
        $output->expects(self::atLeastOnce())
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
        return $output;
    }

    /**
     * @dataProvider provideAutoRemoveFiles
     */
    public function testAutoRemove(string $file): void
    {
        $writtenOutput = '';

        $output = $this->createOutput();
        $output->expects(self::atLeastOnce())
            ->method('writeLineFormatted')
            ->willReturnCallback(static function (string $message) use (&$writtenOutput): void {
                $writtenOutput .= $message . "\n";
            });

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
                    $expectedFile = $this->getAutoremoveTransformedFilePath($file);
                    self::assertFileExists($expectedFile);

                    $expectedNewCode = file_get_contents($expectedFile);
                    self::assertSame($expectedNewCode, $content);
                },
            );

        $analyserErrors = $this->gatherAnalyserErrors([$file]);

        $formatter = new RemoveDeadCodeFormatter($fileSystem, $this->createOutputEnhancer());
        $formatter->formatErrors($this->createAnalysisResult($analyserErrors), $output);

        $expectedOutputFile = $this->getAutoremoveOutputFilePath($file);
        self::assertFileExists($expectedOutputFile);
        self::assertSame(file_get_contents($expectedOutputFile), $this->trimFgColors($writtenOutput), "Output does not match expected: $expectedOutputFile");
    }

    /**
     * @param list<Error> $errors
     */
    private function createAnalysisResult(array $errors): AnalysisResult
    {
        return new AnalysisResult($errors, [], [], [], [], false, null, false, 0, false, []); // @phpstan-ignore phpstanApi.constructor
    }

    /**
     * @return Output&MockObject
     */
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
     * @return Traversable<string, array{0: string|list<string>, 1: list<array{0: string, 1: int, 2?: string|null}>}>
     */
    public static function provideGroupingFiles(): Traversable
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
                    "• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused\n" .
                    "• Thus Grouping\Example::bag is transitively also unused\n" .
                    '• Thus Grouping\Example::bar is transitively also unused',
                ],
                [
                    'Unused Grouping\Example::foo',
                    23,
                    "• Thus Grouping\Example::TRANSITIVELY_UNUSED_CONST is transitively also unused\n" .
                    "• Thus Grouping\Example::bag is transitively also unused\n" .
                    '• Thus Grouping\Example::bar is transitively also unused',
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

        yield 'attribute' => [
            __DIR__ . '/data/grouping/attribute.php',
            [
                [
                    'Unused AttributeGrouping\Foo::endpoint',
                    9,
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
     * @return Traversable<string, array{0: string|list<string>, 1?: bool}>
     */
    public static function provideFiles(): Traversable
    {
        // methods
        yield 'method-anonym' => [__DIR__ . '/data/methods/anonym.php'];
        yield 'method-enum' => [__DIR__ . '/data/methods/enum.php', self::requiresPhp(8_01_00)];
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
        yield 'provider-reflection' => [__DIR__ . '/data/providers/reflection.php', self::requiresPhp(8_01_00)];
        yield 'provider-symfony' => [__DIR__ . '/data/providers/symfony.php', self::requiresPhp(8_00_00)];
        yield 'provider-symfony-7.1' => [__DIR__ . '/data/providers/symfony-gte71.php', self::requiresPhp(8_00_00) && self::requiresPackage('symfony/dependency-injection', '>= 7.1')];
        yield 'provider-phpunit' => [__DIR__ . '/data/providers/phpunit.php', self::requiresPhp(8_00_00)];
        yield 'provider-doctrine' => [__DIR__ . '/data/providers/doctrine.php', self::requiresPhp(8_00_00)];
        yield 'provider-phpstan' => [__DIR__ . '/data/providers/phpstan.php'];
        yield 'provider-nette' => [__DIR__ . '/data/providers/nette.php'];

        // excluders
        yield 'excluder-tests' => [[__DIR__ . '/data/excluders/tests/src/code.php', __DIR__ . '/data/excluders/tests/tests/code.php']];
        yield 'excluder-mixed' => [__DIR__ . '/data/excluders/mixed/code.php'];

        // constants
        yield 'const-basic' => [__DIR__ . '/data/constants/basic.php'];
        yield 'const-function' => [__DIR__ . '/data/constants/constant-function.php'];
        yield 'const-descendant-1' => [__DIR__ . '/data/constants/descendant-1.php'];
        yield 'const-descendant-2' => [__DIR__ . '/data/constants/descendant-2.php'];
        yield 'const-descendant-3' => [__DIR__ . '/data/constants/descendant-3.php'];
        yield 'const-descendant-4' => [__DIR__ . '/data/constants/descendant-4.php'];
        yield 'const-dynamic' => [__DIR__ . '/data/constants/dynamic.php'];
        yield 'const-expr' => [__DIR__ . '/data/constants/expr.php'];
        yield 'const-magic' => [__DIR__ . '/data/constants/magic.php'];
        yield 'const-mixed' => [__DIR__ . '/data/constants/mixed/tracked.php'];
        yield 'const-override' => [__DIR__ . '/data/constants/override.php'];
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

        // mixed member
        yield 'mixed-member-indirect-2' => [__DIR__ . '/data/mixed-member/indirect-interface-2.php'];
        yield 'mixed-member-indirect-4' => [__DIR__ . '/data/mixed-member/indirect-interface-4.php'];
        yield 'mixed-member-indirect-5' => [__DIR__ . '/data/mixed-member/indirect-interface-5.php'];
        yield 'mixed-member-indirect-6' => [__DIR__ . '/data/mixed-member/indirect-interface-6.php'];
        yield 'mixed-member-indirect-7' => [__DIR__ . '/data/mixed-member/indirect-interface-7.php'];
        yield 'mixed-member-indirect-8' => [__DIR__ . '/data/mixed-member/indirect-interface-8.php'];
        yield 'mixed-member-overwriting-1' => [__DIR__ . '/data/mixed-member/overwriting-methods-1.php'];
        yield 'mixed-member-overwriting-2' => [__DIR__ . '/data/mixed-member/overwriting-methods-2.php'];
        yield 'mixed-member-overwriting-3' => [__DIR__ . '/data/mixed-member/overwriting-methods-3.php'];
        yield 'mixed-member-overwriting-4' => [__DIR__ . '/data/mixed-member/overwriting-methods-4.php'];
        yield 'mixed-member-overwriting-5' => [__DIR__ . '/data/mixed-member/overwriting-methods-5.php'];
        yield 'mixed-member-hierarchy-1' => [__DIR__ . '/data/mixed-member/hierarchy-1.php'];
        yield 'mixed-member-hierarchy-2' => [__DIR__ . '/data/mixed-member/hierarchy-2.php'];
        yield 'mixed-member-method-trait-1' => [__DIR__ . '/data/mixed-member/traits-1.php'];
        yield 'mixed-member-method-trait-2' => [__DIR__ . '/data/mixed-member/traits-2.php'];
        yield 'mixed-member-method-trait-3' => [__DIR__ . '/data/mixed-member/traits-3.php'];
        yield 'mixed-member-method-trait-5' => [__DIR__ . '/data/mixed-member/traits-5.php'];
        yield 'mixed-member-method-trait-6' => [__DIR__ . '/data/mixed-member/traits-6.php'];
        yield 'mixed-member-method-trait-7' => [__DIR__ . '/data/mixed-member/traits-7.php'];
        yield 'mixed-member-method-trait-8' => [__DIR__ . '/data/mixed-member/traits-8.php'];
        yield 'mixed-member-method-trait-9' => [__DIR__ . '/data/mixed-member/traits-9.php'];
        yield 'mixed-member-method-trait-10' => [__DIR__ . '/data/mixed-member/traits-10.php'];
        yield 'mixed-member-method-trait-12' => [__DIR__ . '/data/mixed-member/traits-12.php'];
        yield 'mixed-member-method-trait-14' => [__DIR__ . '/data/mixed-member/traits-14.php'];
        yield 'mixed-member-method-trait-15' => [__DIR__ . '/data/mixed-member/traits-15.php'];
        yield 'mixed-member-method-trait-16' => [__DIR__ . '/data/mixed-member/traits-16.php'];
        yield 'mixed-member-method-trait-17' => [__DIR__ . '/data/mixed-member/traits-17.php'];
        yield 'mixed-member-method-trait-18' => [__DIR__ . '/data/mixed-member/traits-18.php'];
        yield 'mixed-member-method-trait-19' => [__DIR__ . '/data/mixed-member/traits-19.php'];
        yield 'mixed-member-method-trait-20' => [__DIR__ . '/data/mixed-member/traits-20.php'];
        yield 'mixed-member-method-trait-21' => [__DIR__ . '/data/mixed-member/traits-21.php'];
        yield 'mixed-member-method-trait-22' => [__DIR__ . '/data/mixed-member/traits-22.php'];
        yield 'mixed-member-method-trait-23' => [__DIR__ . '/data/mixed-member/traits-23.php'];
        yield 'mixed-member-const-traits-1' => [__DIR__ . '/data/mixed-member/traits-const-1.php'];
        yield 'mixed-member-const-traits-2' => [__DIR__ . '/data/mixed-member/traits-const-2.php'];
        yield 'mixed-member-const-traits-3' => [__DIR__ . '/data/mixed-member/traits-const-3.php'];
        yield 'mixed-member-const-traits-5' => [__DIR__ . '/data/mixed-member/traits-const-5.php'];
        yield 'mixed-member-const-traits-7' => [__DIR__ . '/data/mixed-member/traits-const-7.php'];
        yield 'mixed-member-const-traits-9' => [__DIR__ . '/data/mixed-member/traits-const-9.php'];
        yield 'mixed-member-const-traits-10' => [__DIR__ . '/data/mixed-member/traits-const-10.php'];
        yield 'mixed-member-const-traits-14' => [__DIR__ . '/data/mixed-member/traits-const-14.php'];
        yield 'mixed-member-const-traits-21' => [__DIR__ . '/data/mixed-member/traits-const-21.php'];
    }

    /**
     * @return Traversable<string, array{0: string}>
     */
    public static function provideAutoRemoveFiles(): Traversable
    {
        yield 'sample' => [__DIR__ . '/data/removing/sample.php'];
        yield 'no-namespace' => [__DIR__ . '/data/removing/no-namespace.php'];
    }

    private function getAutoremoveTransformedFilePath(string $file): string
    {
        return str_replace('.php', '.transformed.php', $file);
    }

    private function getAutoremoveOutputFilePath(string $file): string
    {
        return str_replace('.php', '.output.txt', $file);
    }

    /**
     * @return list<MemberUsageProvider>
     */
    private function getMemberUsageProviders(): array
    {
        return [
            new ReflectionUsageProvider(
                true,
            ),
            new class extends ReflectionBasedMemberUsageProvider
            {

                public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
                {
                    if ($method->getDeclaringClass()->getName() === 'DeadEntrypoint\Entrypoint') {
                        return VirtualUsageData::withNote('test');
                    }

                    return null;
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
        $excluders = [
            new TestsUsageExcluder(
                self::createReflectionProvider(),
                true,
                [__DIR__ . '/data/excluders/../excluders/tests/tests'], // tests path normalization
            ),
            new class implements MemberUsageExcluder
            {

                public function getIdentifier(): string
                {
                    return 'mixed';
                }

                public function shouldExclude(ClassMemberUsage $usage, Node $node, Scope $scope): bool
                {
                    $memberName = $usage->getMemberRef()->getMemberName();
                    return $memberName !== null && strpos($memberName, 'mixed') === 0;
                }

            },
        ];

        if (!$this->trackMixedAccess) {
            $excluders[] = new MixedUsageExcluder(true);
        }

        return $excluders;
    }

    private function createOutputEnhancer(): OutputEnhancer
    {
        return new OutputEnhancer(
            new SimpleRelativePathHelper(__DIR__), // @phpstan-ignore phpstanApi.constructor
            $this->editorUrl,
        );
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

    private static function requiresPhp(int $lowestPhpVersion): bool
    {
        return PHP_VERSION_ID >= $lowestPhpVersion;
    }

    private static function requiresPackage(string $package, string $constraint): bool
    {
        return InstalledVersions::satisfies(new VersionParser(), $package, $constraint);
    }

    private function trimFgColors(string $output): string
    {
        $replaced = preg_replace(
            '/<fg=[a-z]+>(.*?)<\/>/',
            '$1',
            $output,
        );

        if ($replaced === null) {
            throw new LogicException('Failed to trim colors');
        }

        return $replaced;
    }

}

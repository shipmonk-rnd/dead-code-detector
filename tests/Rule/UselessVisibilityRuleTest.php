<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Collectors\Collector;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\PropertyAccessCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Excluder\MemberUsageExcluder;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use ShipMonk\PHPStan\DeadCode\Processor\CollectedDataProcessor;
use ShipMonk\PHPStan\DeadCode\Provider\ApiPhpDocUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\BuiltinUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\EnumUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VendorUsageProvider;
use ShipMonk\PHPStanDev\RuleTestCase as ShipMonkRuleTestCase;
use Traversable;
use function array_filter;
use function array_merge;
use function is_array;
use function iterator_to_array;
use const PHP_VERSION_ID;

/**
 * @extends ShipMonkRuleTestCase<UselessVisibilityRule>
 */
final class UselessVisibilityRuleTest extends ShipMonkRuleTestCase
{

    use NoFatalErrorTestTrait;

    private bool $detectUselessMethodVisibility = true;

    private bool $detectUselessPropertyVisibility = true;

    private bool $detectUselessConstantVisibility = true;

    private ?UselessVisibilityRule $rule = null;

    protected function getRule(): UselessVisibilityRule
    {
        if ($this->rule === null) {
            $classHierarchy = new ClassHierarchy();
            $this->rule = new UselessVisibilityRule(
                new CollectedDataProcessor($classHierarchy),
                $classHierarchy,
                $this->detectUselessMethodVisibility,
                $this->detectUselessPropertyVisibility,
                $this->detectUselessConstantVisibility,
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
            new ProvidedUsagesCollector(
                $reflectionProvider,
                $this->getMemberUsageProviders(),
                $this->getMemberUsageExcluders(),
            ),
            new ClassDefinitionCollector($reflectionProvider),
            new MethodCallCollector($this->getMemberUsageExcluders()),
            new ConstantFetchCollector($reflectionProvider, $this->getMemberUsageExcluders()),
            new PropertyAccessCollector($this->getMemberUsageExcluders()),
        ];
    }

    /**
     * @param string|non-empty-list<string> $files
     *
     * @dataProvider provideVisibilityFiles
     */
    public function testVisibility(
        $files,
        bool $requirementsMet = true
    ): void
    {
        if (!$requirementsMet) {
            self::markTestSkipped('Requirements not met');
        }

        $this->analyzeFiles(is_array($files) ? $files : [$files]);
    }

    /**
     * @return Traversable<string, array{0: string|non-empty-list<string>, 1?: bool}>
     */
    public static function provideVisibilityFiles(): Traversable
    {
        yield 'visibility-methods' => [__DIR__ . '/data/visibility/methods.php'];
        yield 'visibility-properties' => [__DIR__ . '/data/visibility/properties.php'];
        yield 'visibility-constants' => [__DIR__ . '/data/visibility/constants.php'];
        yield 'visibility-interface' => [__DIR__ . '/data/visibility/interface.php'];
        yield 'visibility-traits' => [__DIR__ . '/data/visibility/traits.php'];
        yield 'visibility-inheritance' => [__DIR__ . '/data/visibility/inheritance.php'];
        yield 'visibility-edge-cases' => [__DIR__ . '/data/visibility/edge-cases.php', self::requiresPhp(8_01_00)];
        yield 'visibility-deep-hierarchy' => [__DIR__ . '/data/visibility/deep-hierarchy.php'];
        yield 'visibility-diamond' => [__DIR__ . '/data/visibility/diamond.php'];
        yield 'visibility-trait-with-hierarchy' => [__DIR__ . '/data/visibility/trait-with-hierarchy.php'];
        yield 'visibility-descendant-override' => [__DIR__ . '/data/visibility/descendant-override.php'];
        yield 'visibility-siblings' => [__DIR__ . '/data/visibility/siblings.php'];
        yield 'visibility-abstract-hierarchy' => [__DIR__ . '/data/visibility/abstract-hierarchy.php'];
        yield 'visibility-static-members' => [__DIR__ . '/data/visibility/static-members.php'];
        yield 'visibility-parent-visibility-floor' => [__DIR__ . '/data/visibility/parent-visibility-floor.php'];
        yield 'visibility-property-access-types' => [__DIR__ . '/data/visibility/property-access-types.php'];
        yield 'visibility-trait-constants-properties' => [__DIR__ . '/data/visibility/trait-constants-properties.php'];
    }

    public function testUselessMethodVisibilityDetectionCanBeDisabled(): void
    {
        $this->detectUselessMethodVisibility = false;

        $filterOwnErrors = static fn (Error $error): bool => $error->getIdentifier() === UselessVisibilityRule::IDENTIFIER_USELESS_METHOD_VISIBILITY;
        self::assertCount(0, array_filter($this->gatherAnalyserErrors([__DIR__ . '/data/visibility/methods.php']), $filterOwnErrors));
    }

    public function testUselessPropertyVisibilityDetectionCanBeDisabled(): void
    {
        $this->detectUselessPropertyVisibility = false;

        $filterOwnErrors = static fn (Error $error): bool => $error->getIdentifier() === UselessVisibilityRule::IDENTIFIER_USELESS_PROPERTY_VISIBILITY;
        self::assertCount(0, array_filter($this->gatherAnalyserErrors([__DIR__ . '/data/visibility/properties.php']), $filterOwnErrors));
    }

    public function testUselessConstantVisibilityDetectionCanBeDisabled(): void
    {
        $this->detectUselessConstantVisibility = false;

        $filterOwnErrors = static fn (Error $error): bool => $error->getIdentifier() === UselessVisibilityRule::IDENTIFIER_USELESS_CONSTANT_VISIBILITY;
        self::assertCount(0, array_filter($this->gatherAnalyserErrors([__DIR__ . '/data/visibility/constants.php']), $filterOwnErrors));
    }

    /**
     * @requires PHP >= 8.5
     */
    public function testNoFatalError(): void
    {
        $this->doTestNoFatalError(
            iterator_to_array(self::provideVisibilityFiles(), false),
        );
    }

    /**
     * @return list<MemberUsageProvider>
     */
    private function getMemberUsageProviders(): array
    {
        return [
            new ReflectionUsageProvider(true),
            new VendorUsageProvider(true),
            new BuiltinUsageProvider(true),
            new ApiPhpDocUsageProvider(
                self::createReflectionProvider(),
                true,
                [__DIR__],
            ),
            new EnumUsageProvider(true),
        ];
    }

    /**
     * @return list<MemberUsageExcluder>
     */
    private function getMemberUsageExcluders(): array
    {
        return [];
    }

    private static function requiresPhp(int $lowestPhpVersion): bool
    {
        return PHP_VERSION_ID >= $lowestPhpVersion;
    }

    /**
     * @param list<Error> $actualErrors
     * @return list<string>
     */
    protected function processActualErrors(array $actualErrors): array
    {
        foreach ($actualErrors as $error) {
            self::assertNotNull($error->getIdentifier(), "Missing error identifier for error: {$error->getMessage()}");
            self::assertStringStartsWith('shipmonk.', $error->getIdentifier(), "Unexpected error identifier for: {$error->getMessage()}");
        }

        return parent::processActualErrors($actualErrors);
    }

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return array_merge(
            parent::getAdditionalConfigFiles(),
            [__DIR__ . '/data/visitors.neon'],
        );
    }

}

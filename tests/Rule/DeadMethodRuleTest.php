<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Provider\EntrypointProvider;

/**
 * @extends RuleTestCase<DeadMethodRule>
 */
class DeadMethodRuleTest extends RuleTestCase
{

    protected function getRule(): DeadMethodRule
    {
        return new DeadMethodRule(self::getContainer()->getByType(ReflectionProvider::class));
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        $entrypointProviders = [
            new class implements EntrypointProvider
            {

                public function isEntrypoint(ReflectionMethod $method): bool
                {
                    return $method->getDeclaringClass()->getName() === 'DeadEntrypoint\Entrypoint';
                }

            },
        ];
        return [
            new MethodDefinitionCollector($entrypointProviders),
            new MethodCallCollector(self::getContainer()->getByType(ReflectionProvider::class)),
        ];
    }

    /**
     * @dataProvider provideFiles
     */
    public function testDead(string $file): void
    {
        $this->analyseFile($file);
    }

    /**
     * @return string[][]
     */
    public static function provideFiles(): array
    {
        return [
            'code' => [__DIR__ . '/data/DeadMethodRule/basic.php'],
            'entrypoint' => [__DIR__ . '/data/DeadMethodRule/entrypoint.php'],
            'first-class-callable' => [__DIR__ . '/data/DeadMethodRule/first-class-callable.php'],
            'overwriting-1' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-1.php'],
            'overwriting-2' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-2.php'],
            'overwriting-3' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-3.php'],
            'overwriting-4' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-4.php'],
            'overwriting-5' => [__DIR__ . '/data/DeadMethodRule/overwriting-methods-5.php'],
            'trait-1' => [__DIR__ . '/data/DeadMethodRule/traits-1.php'],
            'trait-2' => [__DIR__ . '/data/DeadMethodRule/traits-2.php'],
            'trait-3' => [__DIR__ . '/data/DeadMethodRule/traits-3.php'],
            'dead-in-parent-1' => [__DIR__ . '/data/DeadMethodRule/dead-in-parent-1.php'],
            'indirect-interface' => [__DIR__ . '/data/DeadMethodRule/indirect-interface.php'],
            'array-map-1' => [__DIR__ . '/data/DeadMethodRule/array-map-1.php'],
        ];
    }

}

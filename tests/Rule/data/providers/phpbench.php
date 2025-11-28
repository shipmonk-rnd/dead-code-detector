<?php declare(strict_types = 1);

namespace PhpBench;

use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;

#[BeforeMethods('setUp')]
#[AfterMethods('tearDown')]
class SimpleBench
{

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function benchSimpleMethod(): void
    {
    }

    #[ParamProviders(['provideParams'])]
    public function benchWithParams(array $params): void
    {
    }

    #[ParamProviders([1])]
    public function benchWithInvalidAttributeDontBreakIt(array $params): void
    {
    }

    public function provideParams(): array
    {
        return [];
    }

}

class MethodLevelAttributesBench
{

    public function beforeMethod(): void
    {
    }

    public function afterMethod(): void
    {
    }

    #[BeforeMethods('beforeMethod')]
    #[AfterMethods('afterMethod')]
    public function benchSomething(): void
    {
    }

}

/**
 * @BeforeMethods("setUpAnnotation")
 * @AfterMethods({"tearDownAnnotation"})
 */
class AnnotationBench
{

    public function setUpAnnotation(): void
    {
    }

    public function tearDownAnnotation(): void
    {
    }

    public function benchAnnotationStyle(): void
    {
    }

    /**
     * @ParamProviders({"provideDataAnnotation"})
     */
    public function benchWithAnnotationParams(array $params): void
    {
    }

    public function provideDataAnnotation(): array
    {
        return [];
    }

}

class NotBenchmark
{

    public function benchSomething(): void // error: Unused PhpBench\NotBenchmark::benchSomething
    {
    }

}

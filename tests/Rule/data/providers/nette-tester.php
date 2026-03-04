<?php declare(strict_types = 1);

namespace NetteTester;

use Tester\TestCase;

class MyTest extends TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function testBasic(): void
    {
    }

    public function testWithNumbers1(): void
    {
    }

    public function testWith_underscore(): void
    {
    }

    /**
     * @dataProvider provideData
     */
    public function testWithDataProvider(): void
    {
    }

    public function provideData(): array
    {
        return [['a'], ['b']];
    }

    /**
     * @dataProvider fixtures/data.ini
     */
    public function testWithFileDataProvider(): void
    {
    }

    public function helperMethod(): void // error: Unused NetteTester\MyTest::helperMethod
    {
    }
}

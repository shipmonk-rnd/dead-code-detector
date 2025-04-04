<?php declare(strict_types = 1);

namespace Twig;

use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;
use Twig\Attribute\AsTwigTest;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class ProjectExtension
{
    #[AsTwigFilter('rot13')]
    public static function rot13(): void {}

    #[AsTwigFunction('lipsum')]
    public static function lipsum(): void {}

    #[AsTwigTest('even')]
    public static function isEven(): void {}
}

class CustomTwigRuntime
{
    public function filter1(): void {}
    public function filter2(): void {}
    public function func1(): void {}
    public function func2(): void {}
    public function func3(): void {}
    public function test1(): void {}
    public function test2(): void {}
}

new TwigFilter('filter', rand(0, 1) ? [CustomTwigRuntime::class, 'filter1'] : CustomTwigRuntime::class.'::filter2');
new TwigFunction('func', [CustomTwigRuntime::class, rand(0, 1) ? 'func1' : 'func2']);
new TwigFunction('func3', CustomTwigRuntime::class.'::func3');
new TwigTest('test1', [CustomTwigRuntime::class, 'test1']);
new TwigTest(callable: CustomTwigRuntime::class.'::test2', name: 'test2');

<?php declare(strict_types = 1);

namespace Symfony;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Routing\Attribute\Route;

class SomeController2 {

    #[Route('/some', name: 'some')]
    public function getAction(
        #[AutowireIterator(SomeInterface::class, defaultIndexMethod: 'someMethod')]
        $iterator,
        #[AutowireLocator([SomeInterface::class, SomeFooInterface::class], defaultIndexMethod: 'someOther')]
        $locator
    ): void {
    }

}

interface SomeInterface {
    public function someMethod(): string;
    public function someOther(): string;
}

interface SomeFooInterface {
    public function someOther(): string;
}

class SomeThing implements SomeInterface, SomeFooInterface
{
    public function someMethod(): string
    {
        return 'SomeThing';
    }
    public function someOther(): string
    {
        return 'SomeThing';
    }
}

class SomeThing2 implements SomeInterface, SomeFooInterface
{
    public function someMethod(): string
    {
        return 'SomeThing2';
    }
    public function someOther(): string
    {
        return 'SomeThing2';
    }
}

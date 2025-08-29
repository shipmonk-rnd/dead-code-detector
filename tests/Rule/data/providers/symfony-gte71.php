<?php declare(strict_types = 1);

namespace Symfony;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\Attribute\AsAnnounceListener;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsEnterListener;
use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsLeaveListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;

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

class WorkflowEventListener
{
    #[AsAnnounceListener]
    public function announceHandler(): void
    {
    }

    #[AsCompletedListener]
    public function completedHandler(): void
    {
    }

    #[AsEnterListener]
    public function enterHandler(): void
    {
    }

    #[AsEnteredListener]
    public function enteredHandler(): void
    {
    }

    #[AsGuardListener]
    public function guardHandler(): void
    {
    }

    #[AsLeaveListener]
    public function leaveHandler(): void
    {
    }

    #[AsTransitionListener]
    public function transitionHandler(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\WorkflowEventListener::deadMethod
    {
    }
}

// Test AsMessageHandler with default __invoke method
#[AsMessageHandler]
class MessageHandlerWithInvoke
{
    public function __invoke(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\MessageHandlerWithInvoke::deadMethod
    {
    }
}

// Test AsMessageHandler with custom method
#[AsMessageHandler(method: 'handleMessage')]
class MessageHandlerWithCustomMethod
{
    public function handleMessage(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\MessageHandlerWithCustomMethod::deadMethod
    {
    }
}

// Test AsMessageHandler on method level without parameters
class MessageHandlerWithMethodAttribute
{
    #[AsMessageHandler]
    public function handleDirectly(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\MessageHandlerWithMethodAttribute::deadMethod
    {
    }
}

// Test AsMessageHandler on method level with method parameter (edge case)
class MessageHandlerWithMethodLevelRedirect
{
    #[AsMessageHandler(null, null, null, 'actualHandler')]
    public function annotatedMethod(): void // error: Unused Symfony\MessageHandlerWithMethodLevelRedirect::annotatedMethod
    {
    }

    public function actualHandler(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\MessageHandlerWithMethodLevelRedirect::deadMethod
    {
    }
}

// Test AsMessageHandler on method level with named parameter (edge case)
class MessageHandlerWithMethodLevelNamedRedirect
{
    #[AsMessageHandler(method: 'realHandler')]
    public function annotatedMethod(): void // error: Unused Symfony\MessageHandlerWithMethodLevelNamedRedirect::annotatedMethod
    {
    }

    public function realHandler(): void
    {
    }

    public function deadMethod(): void // error: Unused Symfony\MessageHandlerWithMethodLevelNamedRedirect::deadMethod
    {
    }
}

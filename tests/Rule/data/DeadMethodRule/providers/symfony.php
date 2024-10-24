<?php declare(strict_types = 1);

namespace Symfony;

use ShipMonk\Logging\Logger;
use ShipMonk\Logging\StaticLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

class SomeController {

    #[Route('/some', name: 'some')]
    public function getAction(): void {
    }

    #[Required]
    private function setDependency(): void
    {
    }

    #[Unknown] // error: Unused Symfony\SomeController::dead
    public function dead(): void
    {
    }

}

#[AsCommand(name: 'app:create-user')]
class CreateUserCommand extends Command {

    public function __construct() {
        parent::__construct();
    }

}

class FooBundle extends Bundle {
    public function __construct() {
    }
}

class SomeSubscriber implements EventSubscriberInterface
{

    public function onKernelRequest(): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => [['onKernelRequest', 0]],
        ];
    }

}

#[AsController]
class HelloController
{
    public function __construct() {}
}

class DicClassParent { // not present in DIC, but ctor is not dead
    public function __construct() {}
}

class DicClass1 extends DicClassParent {

}

class DicClass2 {
    public function __construct() {}
}

#[\Symfony\Component\HttpKernel\Attribute\AsController]
class DicClass3 {
    public function __construct(DicClass1 $class1, DicClass2 $class2) {}
}

class DicClass4 { // is in DIC, but not used in any other ctor, thus is dead
    public function __construct() {} // error: Unused Symfony\DicClass4::__construct
}

class DicClass5 {}

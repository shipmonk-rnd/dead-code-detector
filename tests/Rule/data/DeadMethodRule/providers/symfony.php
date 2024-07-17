<?php declare(strict_types = 1);

namespace Symfony;

use ShipMonk\Logging\Logger;
use ShipMonk\Logging\StaticLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
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

<?php

namespace DebugRegular;

use Symfony\Component\Routing\Attribute\Route;

class FooController {

    #[Route(path: '/foo', name: 'foo')]
    public function dummyAction(Another $another): void
    {
        $another->call();
    }
}

class Another
{
    public function call(): void
    {
    }
}

<?php declare(strict_types = 1);

namespace SymfonyListenerHeuristic;

// registered as kernel.event_listener via YAML config, which the analyser cannot see;
// the listener methods survive only thanks to the isProbablySymfonyListener heuristic
class YamlRegisteredListener
{

    public function onKernelRequest(): void
    {
    }

    public function onKernelController(): void
    {
    }

    public function onKernelControllerArguments(): void
    {
    }

    public function onKernelView(): void
    {
    }

    public function onKernelFinishRequest(): void
    {
    }

    public function onKernelTerminate(): void
    {
    }

    public function someHelper(): void // error: Unused SymfonyListenerHeuristic\YamlRegisteredListener::someHelper
    {
    }

}

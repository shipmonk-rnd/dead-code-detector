<?php declare(strict_types = 1);

namespace LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsObject;

// --- Actions whose handle() is only reachable through the trait's run() methods ---

class CreateOrder
{
    use AsAction;

    public function handle(int $orderId): void
    {
    }
}

class UpdateOrder
{
    use AsObject;

    public function handle(int $orderId): void
    {
    }
}

class ArchiveOrder
{
    use AsAction;

    public function handle(int $orderId): void
    {
    }
}

// A scalar-typed first parameter, unlike a class-typed one, is not recognised by
// Laravel's own event-listener auto-discovery heuristic - this is the shape that
// exposed the bug this provider fixes.
class ListOrders
{
    use AsAction;

    public function handle(?int $perPage = null): void
    {
    }
}

class UncalledAction
{
    use AsAction;

    public function handle(int $orderId): void // error: Unused LaravelActions\UncalledAction::handle
    {
    }
}

// Not an Action - its own static run() is unrelated to lorisleiva/laravel-actions
class PlainRunner
{
    public static function run(): void
    {
    }

    private static function unusedHelper(): void // error: Unused LaravelActions\PlainRunner::unusedHelper
    {
    }
}

function callActions(): void
{
    CreateOrder::run(1);
    UpdateOrder::runIf(true, 2);
    ArchiveOrder::runUnless(false, 3);
    ListOrders::run();
    PlainRunner::run();
}

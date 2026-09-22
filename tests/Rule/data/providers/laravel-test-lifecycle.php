<?php declare(strict_types = 1);

namespace LaravelTestLifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\WithFaker;

// --- setUpXxx / tearDownXxx hooks, one per used trait ---

trait InteractsWithBilling
{
}

class BillingTest extends TestCase
{
    use InteractsWithBilling;
    use RefreshDatabase;

    public function setUpInteractsWithBilling(): void
    {
    }

    public function tearDownInteractsWithBilling(): void
    {
    }

    public function setupRefreshDatabase(): void // matched case-insensitively, as Laravel uses method_exists()
    {
    }

    public function setUpNoSuchTrait(): void // error: Unused LaravelTestLifecycle\BillingTest::setUpNoSuchTrait
    {
    }

    private function helperMethod(): void // error: Unused LaravelTestLifecycle\BillingTest::helperMethod
    {
    }

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
    }
}

// --- Traits of parent classes count too, as Laravel uses class_uses_recursive() ---

abstract class BaseTest extends TestCase
{
    use WithFaker;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
    }
}

class InheritedTraitTest extends BaseTest
{
    public function setUpWithFaker(): void
    {
    }
}

// --- Not a Laravel test case, so setUpTraits() never runs ---

class NotATestCase
{
    use InteractsWithBilling;

    public function setUpInteractsWithBilling(): void // error: Unused LaravelTestLifecycle\NotATestCase::setUpInteractsWithBilling
    {
    }
}

// --- Properties read through property_exists() ---

class DatabaseSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['mysql'];

    protected bool $seed = true;

    protected string $seeder = 'DatabaseSeeder';

    protected bool $dropViews = true;

    protected bool $dropTypes = true;

    protected array $tablesToTruncate = ['users'];

    protected array $exceptTables = ['migrations'];

    protected array $connectionsToTruncate = ['mysql'];

    protected string $notReadByLaravel = ''; // error: Property LaravelTestLifecycle\DatabaseSettingsTest::$notReadByLaravel is never read

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
    }
}

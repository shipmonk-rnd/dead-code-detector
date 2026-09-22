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

// --- Hooks of an abstract base class, whose subclass is the one using the trait ---

abstract class AbstractBillingTest extends TestCase
{
    public function setUpInteractsWithBilling(): void
    {
    }

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
    }
}

class ConcreteBillingTest extends AbstractBillingTest
{
    use InteractsWithBilling;
}

// --- Hooks may be declared in a trait, and traits of traits count too ---

trait InteractsWithInvoicing
{
}

trait UsesInvoicing
{
    use InteractsWithInvoicing;
}

trait BillingHooks
{
    public function setUpInteractsWithBilling(): void
    {
    }
}

class TraitHookTest extends TestCase
{
    use BillingHooks;
    use InteractsWithBilling;
    use UsesInvoicing;

    public function tearDownInteractsWithInvoicing(): void // nested trait, as class_uses_recursive() walks those too
    {
    }

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
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

    // property names are case-sensitive, unlike the hook methods above
    protected bool $Seed = true; // error: Property LaravelTestLifecycle\DatabaseSettingsTest::$Seed is never read

    protected string $notReadByLaravel = ''; // error: Property LaravelTestLifecycle\DatabaseSettingsTest::$notReadByLaravel is never read

    public function createApplication(): \Illuminate\Foundation\Application
    {
        return new \Illuminate\Foundation\Application();
    }
}

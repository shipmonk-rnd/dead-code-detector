<?php declare(strict_types = 1);

namespace Eloquent;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Seeder;

// --- Eloquent Models ---

class User extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public static function boot(): void
    {
    }

    public static function booted(): void
    {
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }

    protected static function newFactory(): Factory
    {
        return new UserFactory();
    }

    public function scopeActive(object $query): object
    {
        return $query;
    }

    public function posts(): HasMany
    {
        throw new \RuntimeException('stub');
    }

    public function company(): BelongsTo
    {
        throw new \RuntimeException('stub');
    }

    public function roles(): BelongsToMany
    {
        throw new \RuntimeException('stub');
    }

    protected function firstName(): Attribute
    {
        return new Attribute();
    }

    private function notAFrameworkMethod(): void // error: Unused Eloquent\User::notAFrameworkMethod
    {
    }
}

// --- Factories ---

class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }

    public function configure(): static
    {
        return $this;
    }

    private function helperMethod(): void // error: Unused Eloquent\UserFactory::helperMethod
    {
    }
}

// --- Seeders ---

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
    }

    private function helperMethod(): void // error: Unused Eloquent\DatabaseSeeder::helperMethod
    {
    }
}

// --- Migrations ---

class CreateUsersTable extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }

    private function helperMethod(): void // error: Unused Eloquent\CreateUsersTable::helperMethod
    {
    }
}

// --- Observers ---

class UserObserver
{
    public function __construct()
    {
    }

    public function creating(object $user): void
    {
    }

    public function updated(object $user): void
    {
    }

    public function helperMethod(): void // error: Unused Eloquent\UserObserver::helperMethod
    {
    }
}

#[ObservedBy(PostObserver::class)]
class ObservedPost extends Model
{
}

class PostObserver
{
    public function __construct()
    {
    }

    public function saving(object $post): void
    {
    }

    public function deleted(object $post): void
    {
    }

    public function helperMethod(): void // error: Unused Eloquent\PostObserver::helperMethod
    {
    }
}

class AuditObserver
{
    public function __construct()
    {
    }

    public function created(object $model): void
    {
    }

    public function helperMethod(): void // error: Unused Eloquent\AuditObserver::helperMethod
    {
    }
}

// =====================
// Observer Registrations (AST-based detection)
// =====================

function registerObservers(): void
{
    User::observe(UserObserver::class);
    User::observe([AuditObserver::class]);
}

<?php declare(strict_types = 1);

// =====================
// Laravel Stubs
// =====================

namespace Illuminate\Routing;

abstract class Controller
{
}

namespace Illuminate\Database\Eloquent;

abstract class Model
{
    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = []) {}
}

namespace Illuminate\Database\Eloquent\Relations;

abstract class Relation
{
}

class HasMany extends Relation
{
}

class BelongsTo extends Relation
{
}

class BelongsToMany extends Relation
{
}

namespace Illuminate\Database\Eloquent\Casts;

class Attribute
{
}

namespace Illuminate\Database\Eloquent\Factories;

abstract class Factory
{
}

namespace Illuminate\Console;

class Command
{
}

namespace Illuminate\Contracts\Queue;

interface ShouldQueue
{
}

namespace Illuminate\Foundation\Bus;

trait Dispatchable
{
}

namespace Illuminate\Support;

abstract class ServiceProvider
{
    public function __construct($app) {}
}

namespace Illuminate\Http;

class Request
{
}

namespace Illuminate\Notifications;

class Notification
{
}

trait RoutesNotifications
{
}

trait Notifiable
{
    use RoutesNotifications;
}

namespace Illuminate\Foundation\Http;

class FormRequest
{
}

namespace Illuminate\Database;

class Seeder
{
}

namespace Illuminate\Mail;

class Mailable
{
}

namespace Illuminate\Contracts\Broadcasting;

interface ShouldBroadcast
{
}

namespace Illuminate\Http\Resources\Json;

class JsonResource
{
}

namespace Illuminate\Contracts\Validation;

interface ValidationRule
{
}

interface Rule
{
}

namespace Illuminate\Support\Facades;

class Route
{
    /** @param mixed $action */
    public static function get(string $uri, $action): void {}
    /** @param mixed $action */
    public static function post(string $uri, $action): void {}
    /** @param mixed $action */
    public static function put(string $uri, $action): void {}
    /** @param mixed $action */
    public static function patch(string $uri, $action): void {}
    /** @param mixed $action */
    public static function delete(string $uri, $action): void {}
    /** @param mixed $action */
    public static function any(string $uri, $action): void {}
    /**
     * @param list<string> $methods
     * @param mixed $action
     */
    public static function match(array $methods, string $uri, $action): void {}
    public static function resource(string $name, string $controller): void {}
    public static function apiResource(string $name, string $controller): void {}
}

class Event
{
    /** @param mixed $event */
    public static function listen($event, string $listener): void {}
    public static function subscribe(string $subscriber): void {}
}

class Schedule
{
    public static function job(string $job): void {}
}

// =====================
// Test Classes
// =====================

namespace Laravel;

use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Validation\Rule as ValidationRuleOld;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

// --- Controllers (AST-based via Route:: calls) ---

class UserController extends Controller
{
    public function __construct()
    {
    }

    public function index(): void
    {
    }

    public function show(): void
    {
    }

    public function unusedAction(): void // error: Unused Laravel\UserController::unusedAction
    {
    }

    private function helperMethod(): void // error: Unused Laravel\UserController::helperMethod
    {
    }
}

class PostController extends Controller
{
    public function index(): void {}
    public function create(): void {}
    public function store(): void {}
    public function show(): void {}
    public function edit(): void {}
    public function update(): void {}
    public function destroy(): void {}
}

class CommentController extends Controller
{
    public function index(): void {}
    public function store(): void {}
    public function show(): void {}
    public function update(): void {}
    public function destroy(): void {}
    public function create(): void {} // error: Unused Laravel\CommentController::create
    public function edit(): void {} // error: Unused Laravel\CommentController::edit
}

class InvokableController extends Controller
{
    public function __construct()
    {
    }

    public function __invoke(): void
    {
    }

    public function unusedAction(): void // error: Unused Laravel\InvokableController::unusedAction
    {
    }
}

// --- Event Listeners ---

class OrderCreatedEvent
{
}

class OrderEventListener
{
    public function __construct()
    {
    }

    public function handle(): void
    {
    }

    public function unusedMethod(): void // error: Unused Laravel\OrderEventListener::unusedMethod
    {
    }
}

class OrderEventSubscriber
{
    public function __construct()
    {
    }

    public function subscribe(): void
    {
    }

    public function unusedMethod(): void // error: Unused Laravel\OrderEventSubscriber::unusedMethod
    {
    }
}

// --- Scheduled Jobs (AST-based via Schedule:: calls) ---

class CleanupJob
{
    public function __construct()
    {
    }

    public function handle(): void
    {
    }

    public function unusedMethod(): void // error: Unused Laravel\CleanupJob::unusedMethod
    {
    }
}

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
        return new HasMany();
    }

    public function company(): BelongsTo
    {
        return new BelongsTo();
    }

    public function roles(): BelongsToMany
    {
        return new BelongsToMany();
    }

    protected function firstName(): Attribute
    {
        return new Attribute();
    }

    private function notAFrameworkMethod(): void // error: Unused Laravel\User::notAFrameworkMethod
    {
    }
}

// Model with notification routing (routeNotificationFor* belongs on the notifiable model, not Notification)
class NotifiableUser extends Model
{
    use Notifiable;

    public function routeNotificationForSlack(): string
    {
        return '';
    }

    public function routeNotificationForMail(): string
    {
        return '';
    }

    private function notAFrameworkMethod(): void // error: Unused Laravel\NotifiableUser::notAFrameworkMethod
    {
    }
}

// --- Commands ---

class ImportDataCommand extends Command
{
    public function __construct()
    {
    }

    public function handle(): int
    {
        return 0;
    }

    private function helperMethod(): void // error: Unused Laravel\ImportDataCommand::helperMethod
    {
    }
}

// --- Jobs ---

class SendEmailJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct()
    {
    }

    public function handle(): void
    {
    }

    public function failed(): void
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable();
    }

    public function uniqueId(): string
    {
        return '';
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [];
    }

    private function notAJobMethod(): void // error: Unused Laravel\SendEmailJob::notAJobMethod
    {
    }
}

// Job with only Dispatchable trait (no ShouldQueue interface)

class SyncJob
{
    use Dispatchable;

    public function __construct()
    {
    }

    public function handle(): void
    {
    }

    private function privateHelper(): void // error: Unused Laravel\SyncJob::privateHelper
    {
    }
}

// --- Service Providers ---

class AppServiceProvider extends ServiceProvider
{
    public function __construct($app)
    {
        parent::__construct($app);
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\AppServiceProvider::helperMethod
    {
    }
}

// --- Middleware ---

class AuthenticateMiddleware
{
    public function handle(Request $request, \Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\AuthenticateMiddleware::helperMethod
    {
    }
}

// Not a middleware: handle() has no parameters

class NotMiddlewareNoParams
{
    public function handle(): mixed // error: Unused Laravel\NotMiddlewareNoParams::handle
    {
        return null;
    }

    public function terminate(): void // error: Unused Laravel\NotMiddlewareNoParams::terminate
    {
    }
}

// Not a middleware: handle() first param is not Request

class NotMiddlewareWrongParam
{
    public function handle(string $something): mixed // error: Unused Laravel\NotMiddlewareWrongParam::handle
    {
        return null;
    }

    public function terminate(): void // error: Unused Laravel\NotMiddlewareWrongParam::terminate
    {
    }
}

// --- Notifications ---

class InvoiceNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): object
    {
        return new \stdClass();
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [];
    }

    public function toBroadcast(object $notifiable): object
    {
        return new \stdClass();
    }

    public function toVonage(object $notifiable): object
    {
        return new \stdClass();
    }

    public function toSlack(object $notifiable): object
    {
        return new \stdClass();
    }

    private function helperMethod(): void // error: Unused Laravel\InvoiceNotification::helperMethod
    {
    }
}

// --- Form Requests ---

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
    }

    protected function passedValidation(): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\StoreUserRequest::helperMethod
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

    private function helperMethod(): void // error: Unused Laravel\UserFactory::helperMethod
    {
    }
}

// --- Seeders ---

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\DatabaseSeeder::helperMethod
    {
    }
}

// --- Policies ---

class PostPolicy
{
    public function before(object $user): ?bool
    {
        return null;
    }

    public function viewAny(object $user): bool
    {
        return true;
    }

    public function view(object $user, object $post): bool
    {
        return true;
    }

    public function create(object $user): bool
    {
        return true;
    }

    public function update(object $user, object $post): bool
    {
        return true;
    }

    public function delete(object $user, object $post): bool
    {
        return true;
    }

    public function restore(object $user, object $post): bool
    {
        return true;
    }

    public function forceDelete(object $user, object $post): bool
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\PostPolicy::helperMethod
    {
    }
}

// --- Mailables ---

class WelcomeEmail extends Mailable
{
    public function build(): self
    {
        return $this;
    }

    public function content(): object
    {
        return new \stdClass();
    }

    public function envelope(): object
    {
        return new \stdClass();
    }

    /** @return list<object> */
    public function attachments(): array
    {
        return [];
    }

    public function headers(): object
    {
        return new \stdClass();
    }

    private function helperMethod(): void // error: Unused Laravel\WelcomeEmail::helperMethod
    {
    }
}

// --- Broadcast Events ---

class OrderShipped implements ShouldBroadcast
{
    /** @return list<string> */
    public function broadcastOn(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return '';
    }

    public function broadcastWhen(): bool
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\OrderShipped::helperMethod
    {
    }
}

// --- JSON Resources ---

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(object $request): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function with(object $request): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function additional(object $request): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function paginationInformation(object $request): array
    {
        return [];
    }

    private function helperMethod(): void // error: Unused Laravel\UserResource::helperMethod
    {
    }
}

// --- Validation Rules ---

class UppercaseRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\UppercaseRule::helperMethod
    {
    }
}

class OldValidationRule implements ValidationRuleOld
{
    public function passes(string $attribute, mixed $value): bool
    {
        return true;
    }

    public function message(): string
    {
        return '';
    }

    private function helperMethod(): void // error: Unused Laravel\OldValidationRule::helperMethod
    {
    }
}

// =====================
// Route/Event/Schedule Registrations (AST-based detection)
// =====================

function registerRoutes(): void
{
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'index']);
    Route::patch('/users/{id}', [UserController::class, 'show']);
    Route::delete('/users/{id}', [UserController::class, 'index']);
    Route::any('/any', [UserController::class, 'show']);
    Route::match(['GET', 'POST'], '/match', [UserController::class, 'index']);
    Route::get('/invokable', InvokableController::class);
    Route::resource('posts', PostController::class);
    Route::apiResource('comments', CommentController::class);
}

function registerEvents(): void
{
    Event::listen(OrderCreatedEvent::class, OrderEventListener::class);
    Event::subscribe(OrderEventSubscriber::class);
}

function registerSchedule(): void
{
    Schedule::job(CleanupJob::class);
}

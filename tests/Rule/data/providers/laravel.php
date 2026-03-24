<?php declare(strict_types = 1);

namespace Laravel;

use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Validation\Rule as ValidationRuleOld;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

class MatchInvokableController extends Controller
{
    public function __construct()
    {
    }

    public function __invoke(): void
    {
    }

    public function unusedAction(): void // error: Unused Laravel\MatchInvokableController::unusedAction
    {
    }
}

class StringRouteController extends Controller
{
    public function __construct()
    {
    }

    public function index(): void
    {
    }

    public function store(): void
    {
    }

    public function unusedAction(): void // error: Unused Laravel\StringRouteController::unusedAction
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

// --- Auto-discovered Event Listeners (reflection-based) ---

class OrderShippedEvent
{
}

class AutoDiscoveredListener
{
    public function __construct()
    {
    }

    public function handle(OrderCreatedEvent $event): void
    {
    }

    public function unusedMethod(): void // error: Unused Laravel\AutoDiscoveredListener::unusedMethod
    {
    }
}

class MultiHandlerListener
{
    public function __construct()
    {
    }

    public function handleOrderCreated(OrderCreatedEvent $event): void
    {
    }

    public function handleOrderShipped(OrderShippedEvent $event): void
    {
    }

    public function unusedMethod(): void // error: Unused Laravel\MultiHandlerListener::unusedMethod
    {
    }
}

class InvokableListener
{
    public function __construct()
    {
    }

    public function __invoke(OrderCreatedEvent $event): void
    {
    }
}

// Auto-discovered with union type parameter

class UnionTypeListener
{
    public function handle(OrderCreatedEvent|OrderShippedEvent $event): void
    {
    }
}

// Not auto-discovered: private handle method

class NotListenerPrivateHandle
{
    private function handle(OrderCreatedEvent $event): void // error: Unused Laravel\NotListenerPrivateHandle::handle
    {
    }
}

// Not auto-discovered: no params (handle without type-hinted event)

class NotListenerNoParams
{
    public function handle(): void // error: Unused Laravel\NotListenerNoParams::handle
    {
    }
}

// Not auto-discovered: first param is built-in type

class NotListenerBuiltinParam
{
    public function handle(string $eventId): void // error: Unused Laravel\NotListenerBuiltinParam::handle
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

    public function uniqueVia(): object
    {
        return new \stdClass();
    }

    public function displayName(): string
    {
        return '';
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

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
    }

    protected function failedAuthorization(): void
    {
    }

    private function helperMethod(): void // error: Unused Laravel\StoreUserRequest::helperMethod
    {
    }
}

// --- Policy with custom abilities (detected via authorize() and Gate::policy()) ---

class Song extends Model
{
}

class Album extends Model
{
}

class Post extends Model
{
}

class User extends Model
{
    use Authorizable;
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

    public function additional(array $data): static
    {
        return $this;
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
    public function passes($attribute, $value): bool
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

// --- Middleware with constructor ---

class ThrottleMiddleware
{
    public function __construct()
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        return $next($request);
    }

    private function helperMethod(): void // error: Unused Laravel\ThrottleMiddleware::helperMethod
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
    Route::match(['GET'], '/match-invokable', MatchInvokableController::class);
    Route::get('/invokable', InvokableController::class);
    Route::resource('posts', PostController::class);
    Route::apiResource('comments', CommentController::class);

    // String syntax: 'Controller@method'
    Route::get('/string-route', 'Laravel\StringRouteController@index');
    Route::post('/string-route', 'Laravel\StringRouteController@store');
    Route::match(['GET'], '/string-match', 'Laravel\StringRouteController@index');
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

function registerPolicies(): void
{
    Gate::policy(Album::class, \Laravel\Policies\AlbumPolicy::class);
    Gate::define('manage-songs', [\Laravel\Policies\SongPolicy::class, 'access']);
    Gate::define('check-license', \Laravel\Policies\LicenseChecker::class);
    Route::get('/songs/{song}', [SongController::class, 'show']);
    Route::get('/songs/{song}/download', [SongController::class, 'download']);
    Route::post('/songs/upload', [SongController::class, 'upload']);
}

// --- can/cannot/cant and Gate::allows/denies/check (policy resolution via Authorizable) ---

function testCanAndGateMethods(Post $post, Song $song, User $user): void
{
    $user->can('publish', $post);          // PostPolicy::publish
    $user->cannot('archive', $post);       // PostPolicy::archive
    $user->cant('draft', $post);           // PostPolicy::draft
    $user->can('access', Album::class);    // AlbumPolicy::access (class-string)
    Gate::allows('approve', $song);        // SongPolicy::approve
    Gate::denies('reject', $song);         // SongPolicy::reject
    Gate::check('flag', $song);            // SongPolicy::flag
}

// --- Controller using authorize() ---

class SongController extends Controller
{
    use AuthorizesRequests;

    public function show(Song $song): void
    {
        $this->authorize('access', $song);
        $this->authorize('edit', $song);
    }

    public function download(Song $song): void
    {
        $this->authorize('force-download', $song);
    }

    public function upload(): void
    {
        $this->authorize('access', Album::class);
        $this->authorize('view', new User()); // User has no policy in Laravel\Policies namespace
    }
}

// =====================
// Policy Classes (in sub-namespace for convention-based resolution)
// =====================

namespace Laravel\Policies;

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

    public function publish(object $user, object $post): bool
    {
        return true;
    }

    public function archive(object $user, object $post): bool
    {
        return true;
    }

    public function draft(object $user, object $post): bool
    {
        return true;
    }

    public function unusedCustomAbility(object $user): bool // error: Unused Laravel\Policies\PostPolicy::unusedCustomAbility
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\Policies\PostPolicy::helperMethod
    {
    }
}

class SongPolicy
{
    public function access(object $user, object $song): bool
    {
        return true;
    }

    public function edit(object $user, object $song): bool
    {
        return true;
    }

    public function forceDownload(object $user, object $song): bool
    {
        return true;
    }

    public function approve(object $user, object $song): bool
    {
        return true;
    }

    public function reject(object $user, object $song): bool
    {
        return true;
    }

    public function flag(object $user, object $song): bool
    {
        return true;
    }

    public function unusedAbility(object $user): bool // error: Unused Laravel\Policies\SongPolicy::unusedAbility
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\Policies\SongPolicy::helperMethod
    {
    }
}

class AlbumPolicy
{
    public function access(object $user): bool
    {
        return true;
    }

    public function browse(object $user): bool
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\Policies\AlbumPolicy::helperMethod
    {
    }
}

class LicenseChecker
{
    public function __construct()
    {
    }

    public function __invoke(object $user): bool
    {
        return true;
    }

    private function helperMethod(): void // error: Unused Laravel\Policies\LicenseChecker::helperMethod
    {
    }
}

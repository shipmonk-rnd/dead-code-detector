<?php declare(strict_types = 1);

// Mock Laravel base classes

namespace Illuminate\Console;

abstract class Command
{
    public function handle(): void // error: Unused Illuminate\Console\Command::handle
    {
    }
}

namespace Illuminate\Support;

abstract class ServiceProvider
{
    public function register(): void // error: Unused Illuminate\Support\ServiceProvider::register
    {
    }

    public function boot(): void // error: Unused Illuminate\Support\ServiceProvider::boot
    {
    }
}

namespace Illuminate\Database\Eloquent;

abstract class Model
{
}

namespace Illuminate\Support\Facades;

class Route
{

    /**
     * @param string $uri
     * @param mixed $action
     */
    public static function get(string $uri, $action): void
    {
    }

    /**
     * @param string $uri
     * @param mixed $action
     */
    public static function post(string $uri, $action): void
    {
    }

}

// ---

namespace LaravelProvider;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

// Routes

class HomeController
{

    public function index(): void
    {
    }

    public function create(): void
    {
    }

    public function notRegistered(): void // error: Unused LaravelProvider\HomeController::notRegistered
    {
    }

}

Route::get('/home', [HomeController::class, 'index']);
Route::post('/home', 'LaravelProvider\HomeController@create');

// Artisan commands

class SendEmailsCommand extends Command
{

    public function handle(): void
    {
    }

    private function buildMessage(): string // error: Unused LaravelProvider\SendEmailsCommand::buildMessage
    {
        return '';
    }

}

// Service providers

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {
    }

    public function boot(): void
    {
    }

    private function privateHelper(): void // error: Unused LaravelProvider\AppServiceProvider::privateHelper
    {
    }

}

// Eloquent accessors and mutators

class User extends Model
{

    public function getFullNameAttribute(): string
    {
        return '';
    }

    public function setFullNameAttribute(string $value): void
    {
    }

    public function notAnAccessor(): string // error: Unused LaravelProvider\User::notAnAccessor
    {
        return '';
    }

}

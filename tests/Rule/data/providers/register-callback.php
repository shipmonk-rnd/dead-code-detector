<?php declare(strict_types = 1);

namespace RegisterCallback;

class StreamWrapper
{
    public function stream_open($path, $mode, $options, &$opened_path): bool { return true; }
    public function stream_read($count): string { return ''; }
    public function stream_write($data): int { return 0; }
    public function stream_close(): void {}
    public function stream_eof(): bool { return true; }
    public function stream_tell(): int { return 0; }
    public function stream_seek($offset, $whence): bool { return true; }
    public function stream_stat(): array { return []; }
    public function url_stat($path, $flags): array { return []; }
    public function unusedMethod(): void {} // error: Unused RegisterCallback\StreamWrapper::unusedMethod
}

stream_wrapper_register('custom', StreamWrapper::class);

class StreamFilter
{
    public function filter($in, $out, &$consumed, $closing): int { return PSFS_PASS_ON; }
    public function onCreate(): bool { return true; }
    public function onClose(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\StreamFilter::unusedMethod
}

stream_filter_register('my.filter', StreamFilter::class);

class ShutdownHandler
{
    public static function handle(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\ShutdownHandler::unusedMethod
}

register_shutdown_function([ShutdownHandler::class, 'handle']);

class TickHandler
{
    public static function tick(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\TickHandler::unusedMethod
}

register_tick_function([TickHandler::class, 'tick']);

class HeaderCallback
{
    public static function callback(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\HeaderCallback::unusedMethod
}

header_register_callback([HeaderCallback::class, 'callback']);

class AutoloadHandler
{
    public static function load($class): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\AutoloadHandler::unusedMethod
}

spl_autoload_register([AutoloadHandler::class, 'load']);

// Test with string callable
class StaticCallableHandler
{
    public static function staticMethod(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\StaticCallableHandler::unusedMethod
}

register_shutdown_function('RegisterCallback\StaticCallableHandler::staticMethod');

// Test with object callable
class ObjectCallableHandler
{
    public function handle(): void {}
    public function unusedMethod(): void {} // error: Unused RegisterCallback\ObjectCallableHandler::unusedMethod
}

$handler = new ObjectCallableHandler();
register_shutdown_function([$handler, 'handle']);

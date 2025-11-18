<?php declare(strict_types = 1);

namespace RegisterCallback;

// Test stream_wrapper_register
// Note: Stream wrapper/filter class registration detection needs more work
class MyStreamWrapper
{
    public function stream_open($path, $mode, $options, &$opened_path) {} // error: Unused RegisterCallback\MyStreamWrapper::stream_open
    public function stream_read($count) {} // error: Unused RegisterCallback\MyStreamWrapper::stream_read
}

class UnusedStreamWrapper
{
    public function stream_open($path, $mode, $options, &$opened_path) {} // error: Unused RegisterCallback\UnusedStreamWrapper::stream_open
}

// Test stream_filter_register
class MyStreamFilter extends \php_user_filter
{
    public function filter($in, $out, &$consumed, $closing) {}
    public function onCreate() {}
}

class UnusedStreamFilter
{
    public function filter($in, $out, &$consumed, $closing) {} // error: Unused RegisterCallback\UnusedStreamFilter::filter
}

// Test register_shutdown_function
class ShutdownHandler
{
    public function handleShutdown() {}
    public static function staticShutdown() {}
    public function unusedMethod() {} // error: Unused RegisterCallback\ShutdownHandler::unusedMethod
}

function shutdownFunction() {}
function unusedFunction() {}

// Test spl_autoload_register
class AutoloadHandler
{
    public function autoload($class) {}
    public static function staticAutoload($class) {}
    public function unusedMethod() {} // error: Unused RegisterCallback\AutoloadHandler::unusedMethod
}

// Test register_tick_function
class TickHandler
{
    public static function onTick() {}
    public function unusedMethod() {} // error: Unused RegisterCallback\TickHandler::unusedMethod
}

// Test header_register_callback
class HeaderHandler
{
    public static function onHeadersSent() {}
    public function unusedMethod() {} // error: Unused RegisterCallback\HeaderHandler::unusedMethod
}

function testAll() {
    // Register stream wrapper
    stream_wrapper_register('myprotocol', MyStreamWrapper::class);

    // Register stream filter
    stream_filter_register('myfilter', MyStreamFilter::class);

    // String function name
    register_shutdown_function('RegisterCallback\shutdownFunction');

    // Array callable with class name string and method
    register_shutdown_function([ShutdownHandler::class, 'staticShutdown']);

    // Array callable with object instance
    $shutdownHandler = new ShutdownHandler();
    register_shutdown_function([$shutdownHandler, 'handleShutdown']);

    // Static method as string with ::
    register_shutdown_function('RegisterCallback\ShutdownHandler::staticShutdown');

    // spl_autoload_register with array callable
    spl_autoload_register([AutoloadHandler::class, 'staticAutoload']);

    // spl_autoload_register with object instance
    $autoloadHandler = new AutoloadHandler();
    spl_autoload_register([$autoloadHandler, 'autoload']);

    // register_tick_function
    register_tick_function([TickHandler::class, 'onTick']);

    // header_register_callback
    header_register_callback([HeaderHandler::class, 'onHeadersSent']);

    // Dynamic callable (should still work if type can be resolved)
    $callable = [EdgeCaseHandler::class, 'method1'];
    register_shutdown_function($callable);
}

// Edge cases
class EdgeCaseHandler
{
    public function method1() {} // error: Unused RegisterCallback\EdgeCaseHandler::method1
    public function method2() {} // error: Unused RegisterCallback\EdgeCaseHandler::method2
}

// Actually call the test function
testAll();

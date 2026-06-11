<?php

namespace ArrayCallableDynamicName;

class Test {

    public function run(string $methodName): void
    {
        // known class, unknown method name => marks all methods of Test as used
        if (is_callable([$this, $methodName])) {
            call_user_func([$this, $methodName]);
        }
    }

    public function maybeCalledDynamically(): void
    {
    }

}

class StaticHandler {

    public static function maybeCalledOverDynamicClassString(): void
    {
    }

    public static function deadMethod(): void // error: Unused ArrayCallableDynamicName\StaticHandler::deadMethod
    {
    }

}

function runOverDynamicClassString(string $className): void
{
    // unknown class, known method name => marks the method as used in all classes declaring it
    if (is_callable([$className, 'maybeCalledOverDynamicClassString'])) {
        call_user_func([$className, 'maybeCalledOverDynamicClassString']);
    }
}

function runFullyUnknown(string $className, string $methodName): void
{
    // unknown class, unknown method name => marks nothing as used
    if (is_callable([$className, $methodName])) {
        call_user_func([$className, $methodName]);
    }
}

(new Test())->run('maybeCalledDynamically');
runOverDynamicClassString(StaticHandler::class);
runFullyUnknown('AnyClass', 'anyMethod');
